#!/usr/bin/env python3
"""
HLS segment archive uploader.

Mirrors the transcoder's HLS output to S3 and maintains a per-hour index, so that a
recording can later be cut by selecting a range of segments and writing a playlist,
rather than concatenating and re-encoding MP4s. See docs/dvr-archive-plan.md.

This is the only copy. The SRS MP4 DVR that used to sit behind it as a cold backup
was retired once the segment path was verified frame-exact, so a segment lost before
it reaches S3 is lost outright. That is what the verified reaper defends: it refuses
to delete anything S3 has not confirmed.

Three jobs, in one process because they all need the same view of the segment
directory and the same parse of the playlists:

  index   read each rendition playlist, record what it says about segments that are
          now complete, before the sliding window forgets them
  upload  copy those segments to S3 and verify the copy landed
  reap    delete local segments that S3 has confirmed and that have aged out of the
          live rewind window

Correctness rests on a reconciling sweep rather than inotify. Segments arrive every
two seconds, so a short polling interval is both simpler and sufficient, and a sweep
recovers from a crash without needing to have observed the events it missed.
"""

import logging
import os
import sqlite3
import threading
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

import boto3
from botocore.config import Config
from botocore.exceptions import ClientError

logging.basicConfig(
    level=os.environ.get('LOG_LEVEL', 'INFO').upper(),
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
)
logger = logging.getLogger('archive-uploader')

# ----------------------------------------------------------------- configuration

S3_BUCKET = os.environ.get('S3_BUCKET', 'streaming-recordings')
S3_REGION = os.environ.get('S3_REGION', 'eu-central-1')
S3_ACCESS_KEY = os.environ.get('S3_ACCESS_KEY')
S3_SECRET_KEY = os.environ.get('S3_SECRET_KEY')
S3_ENDPOINT = os.environ.get('S3_ENDPOINT')

# Where the transcoder writes. This is the hls-content volume, not the SRS DVR one.
HLS_PATH = os.environ.get('HLS_PATH', '/var/www/hls/live')

# Where the transcoder mirrors the publisher's own bitstream, remuxed rather than
# re-encoded. A sibling of HLS_PATH rather than a subdirectory of it, because
# nginx serves HLS_PATH's parent under `location ~ ^/live/` and anything inside it
# is therefore reachable by a viewer. This rendition is archive-only.
#
# Its segments are cut on the publisher's keyframes, not on the ladder's forced 2s
# marks, so it cannot share the ladder's index entries and gets its own hour
# playlist (index-source.m3u8) alongside them.
SOURCE_HLS_PATH = os.environ.get('SOURCE_HLS_PATH', '/var/www/hls/source')
SOURCE_RENDITION = os.environ.get('SOURCE_RENDITION', 'source')
ARCHIVE_SOURCE = os.environ.get('ARCHIVE_SOURCE', '1') not in ('0', 'false', 'False', '')

# Prefix for raw segments and hour indexes.
ARCHIVE_PREFIX = os.environ.get('ARCHIVE_PREFIX', 'archive')

# Local staging for hour index playlists. Kept on disk so a restart does not have to
# rebuild an hour it already wrote, and so the index survives an S3 outage.
INDEX_PATH = os.environ.get('INDEX_PATH', '/var/lib/dvr-archive/index')
MANIFEST_DB = os.environ.get('MANIFEST_DB', '/var/lib/dvr-archive/manifest.sqlite')

SWEEP_INTERVAL = int(os.environ.get('SWEEP_INTERVAL', '5'))
INDEX_UPLOAD_INTERVAL = int(os.environ.get('INDEX_UPLOAD_INTERVAL', '60'))
REAP_INTERVAL = int(os.environ.get('REAP_INTERVAL', '120'))

# A segment is never deleted locally while it is still inside the live rewind window,
# regardless of whether S3 has it. Must be >= the transcoder's DVR window.
DVR_WINDOW_SECONDS = int(os.environ.get('DVR_WINDOW_SECONDS', '3600'))

MAX_CONCURRENT_UPLOADS = int(os.environ.get('MAX_CONCURRENT_UPLOADS', '5'))

# Ceiling on upload bandwidth, so continuous archive traffic cannot starve the edge
# of the origin's uplink. 0 disables the limit. Replaces MAX_UPLOAD_RATE_MBPS, which
# was configured on the origin for a long time but never actually read.
MAX_UPLOAD_RATE_MBPS = float(os.environ.get('MAX_UPLOAD_RATE_MBPS', '0'))

RENDITIONS = [r for r in os.environ.get('ARCHIVE_RENDITIONS', 'sd,hd,fhd').split(',') if r]

# Which rendition's playlist is treated as authoritative for timing. The others are
# checked against it rather than parsed independently.
CANONICAL_RENDITION = os.environ.get('CANONICAL_RENDITION', 'hd')

boto_config = Config(
    max_pool_connections=100,
    retries={'max_attempts': 5, 'mode': 'adaptive'},
    read_timeout=120,
    connect_timeout=30,
)

_s3_kwargs = {'region_name': S3_REGION, 'config': boto_config}
if S3_ACCESS_KEY and S3_SECRET_KEY:
    _s3_kwargs['aws_access_key_id'] = S3_ACCESS_KEY
    _s3_kwargs['aws_secret_access_key'] = S3_SECRET_KEY
if S3_ENDPOINT:
    _s3_kwargs['endpoint_url'] = S3_ENDPOINT

s3 = boto3.client('s3', **_s3_kwargs)

upload_semaphore = threading.Semaphore(MAX_CONCURRENT_UPLOADS)

# Paths a worker thread currently owns. See dispatch_uploads.
inflight = set()
inflight_lock = threading.Lock()

rate_limiter_lock = threading.Lock()
_rate_tokens = {'bytes': 0.0, 'at': time.monotonic()}

metrics = {'indexed': 0, 'uploaded': 0, 'verified': 0, 'reaped': 0, 'failed': 0}
metrics_lock = threading.Lock()


# ---------------------------------------------------------------------- manifest

class Manifest:
    """
    Durable record of what has been indexed, uploaded and verified.

    Everything here is keyed so that repeating work is harmless: the sweep re-reads
    the same playlist entries every few seconds and must converge rather than
    duplicate. A crash costs at most the in-flight uploads, which the next sweep
    picks up again.
    """

    def __init__(self, path):
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        self._local = threading.local()
        self._path = path
        with self._conn() as c:
            c.executescript("""
                CREATE TABLE IF NOT EXISTS segments (
                    path        TEXT PRIMARY KEY,
                    key         TEXT NOT NULL,
                    source      TEXT NOT NULL,
                    size        INTEGER,
                    etag        TEXT,
                    uploaded_at REAL,
                    verified_at REAL
                );
                CREATE INDEX IF NOT EXISTS segments_verified
                    ON segments (verified_at);

                -- One row per logical segment (all renditions share it), carrying the
                -- monotonic ordering key. Assigned on first observation, never reused.
                CREATE TABLE IF NOT EXISTS indexed (
                    source   TEXT NOT NULL,
                    session  TEXT NOT NULL,
                    n        INTEGER NOT NULL,
                    seq      INTEGER NOT NULL,
                    hour     TEXT NOT NULL,
                    PRIMARY KEY (source, session, n)
                );

                CREATE TABLE IF NOT EXISTS counters (
                    source   TEXT PRIMARY KEY,
                    next_seq INTEGER NOT NULL
                );
            """)

    def _conn(self):
        # sqlite connections are not shareable across threads.
        if not hasattr(self._local, 'conn'):
            self._local.conn = sqlite3.connect(self._path, timeout=30)
            self._local.conn.execute('PRAGMA journal_mode=WAL')
        return self._local.conn

    def next_seq(self, source, count=1):
        """Reserve `count` ordering keys for a source."""
        with self._conn() as c:
            row = c.execute(
                'SELECT next_seq FROM counters WHERE source = ?', (source,)
            ).fetchone()
            start = row[0] if row else 0
            c.execute(
                'INSERT INTO counters (source, next_seq) VALUES (?, ?) '
                'ON CONFLICT(source) DO UPDATE SET next_seq = ?',
                (source, start + count, start + count),
            )
        return start

    def already_indexed(self, source, session, n):
        with self._conn() as c:
            return c.execute(
                'SELECT 1 FROM indexed WHERE source = ? AND session = ? AND n = ?',
                (source, session, n),
            ).fetchone() is not None

    def record_indexed(self, source, session, n, seq, hour):
        with self._conn() as c:
            c.execute(
                'INSERT OR IGNORE INTO indexed (source, session, n, seq, hour) '
                'VALUES (?, ?, ?, ?, ?)',
                (source, session, n, seq, hour),
            )

    def known(self, path):
        with self._conn() as c:
            return c.execute(
                'SELECT 1 FROM segments WHERE path = ?', (str(path),)
            ).fetchone() is not None

    def record_pending(self, path, key, source):
        with self._conn() as c:
            c.execute(
                'INSERT OR IGNORE INTO segments (path, key, source) VALUES (?, ?, ?)',
                (str(path), key, source),
            )

    def record_verified(self, path, size, etag):
        with self._conn() as c:
            c.execute(
                'UPDATE segments SET size = ?, etag = ?, uploaded_at = ?, '
                'verified_at = ? WHERE path = ?',
                (size, etag, time.time(), time.time(), str(path)),
            )

    def pending_uploads(self, limit=500):
        with self._conn() as c:
            return c.execute(
                'SELECT path, key, source FROM segments WHERE verified_at IS NULL '
                'LIMIT ?', (limit,)
            ).fetchall()

    def pending_count(self):
        with self._conn() as c:
            return c.execute(
                'SELECT COUNT(*) FROM segments WHERE verified_at IS NULL'
            ).fetchone()[0]

    def reapable(self, limit=2000):
        with self._conn() as c:
            return c.execute(
                'SELECT path, key, size FROM segments WHERE verified_at IS NOT NULL '
                'LIMIT ?', (limit,)
            ).fetchall()

    def forget(self, path):
        with self._conn() as c:
            c.execute('DELETE FROM segments WHERE path = ?', (str(path),))


# ------------------------------------------------------------- playlist parsing

class Entry:
    """One segment as the playlist describes it."""

    __slots__ = ('name', 'duration', 'pdt', 'discontinuity', 'source', 'rendition',
                 'session', 'n')

    def __init__(self, name, duration, pdt, discontinuity):
        self.name = name
        self.duration = duration
        self.pdt = pdt
        self.discontinuity = discontinuity
        # prime_hd_1785710235_000042.ts -> (prime, hd, 1785710235, 42)
        stem = name[:-3] if name.endswith('.ts') else name
        parts = stem.rsplit('_', 3)
        self.source, self.rendition, self.session, n = parts
        self.n = int(n)

    @property
    def hour(self):
        """Bucket by the segment's start, so a segment straddling the boundary
        belongs to the earlier hour."""
        return self.pdt.strftime('%Y%m%d/%H')

    def generic_name(self):
        """Name with the rendition replaced by %v, so one index entry covers all."""
        return f'{self.source}_%v_{self.session}_{self.n:06d}.ts'


def parse_playlist(path):
    """
    Returns (entries, complete_count).

    The last entry is excluded from `complete_count` because FFmpeg appends a segment
    to the playlist as it finishes writing it; only once a *further* segment appears
    is the previous one certainly closed. That is exact, unlike watching the file size
    settle, which is what the MP4 uploader has to do.
    """
    try:
        text = Path(path).read_text()
    except (OSError, UnicodeDecodeError):
        return [], 0

    entries = []
    duration = None
    pdt = None
    discontinuity = False

    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith('#EXTINF:'):
            try:
                duration = float(line[8:].split(',')[0])
            except ValueError:
                duration = None
        elif line.startswith('#EXT-X-PROGRAM-DATE-TIME:'):
            pdt = _parse_pdt(line.split(':', 1)[1])
        elif line == '#EXT-X-DISCONTINUITY':
            discontinuity = True
        elif not line.startswith('#'):
            if duration is not None and pdt is not None:
                try:
                    entries.append(Entry(line, duration, pdt, discontinuity))
                except (ValueError, IndexError):
                    logger.debug('Unparseable segment name, skipping: %s', line)
            duration = None
            pdt = None
            discontinuity = False

    return entries, max(0, len(entries) - 1)


def _parse_pdt(value):
    """FFmpeg writes 2026-08-02T22:37:17.805+0000."""
    value = value.strip()
    try:
        # Python needs +00:00 rather than +0000 before 3.11.
        if len(value) >= 5 and value[-5] in '+-' and ':' not in value[-5:]:
            value = value[:-2] + ':' + value[-2:]
        return datetime.fromisoformat(value)
    except ValueError:
        return None


def discover_sources():
    """Streams currently being transcoded, from the master playlists on disk."""
    base = Path(HLS_PATH)
    if not base.is_dir():
        return []
    return sorted(p.name[:-len('_master.m3u8')] for p in base.glob('*_master.m3u8'))


# ---------------------------------------------------------------------- indexing

class Indexer:
    """
    Writes one HLS playlist per source per hour, which is the durable record of what
    the sliding live playlist used to say.

    The stored form is a playlist rather than JSON on purpose: cutting a recording
    then means concatenating hour files, dropping the entries outside the range and
    appending ENDLIST, with no format conversion anywhere.
    """

    HEADER = (
        '#EXTM3U\n'
        '#EXT-X-VERSION:6\n'
        '#EXT-X-TARGETDURATION:2\n'
        '#EXT-X-INDEPENDENT-SEGMENTS\n'
    )

    def __init__(self, manifest):
        self.manifest = manifest
        self.dirty = set()
        self.lock = threading.Lock()

    # The source rendition splits on the publisher's keyframes, so its segments can
    # be any length the encoder's GOP happens to be. Nothing plays this file
    # directly (ArchivePlaylistService recomputes TARGETDURATION per cut), but it
    # should not claim 2s when it means 10.
    SOURCE_HEADER = (
        '#EXTM3U\n'
        '#EXT-X-VERSION:6\n'
        '#EXT-X-TARGETDURATION:10\n'
        '#EXT-X-INDEPENDENT-SEGMENTS\n'
    )

    def local_path(self, source, hour, name='index.m3u8'):
        return Path(INDEX_PATH) / source / hour / name

    def s3_key(self, source, hour, name='index.m3u8'):
        return f'{ARCHIVE_PREFIX}/{source}/{hour}/{name}'

    def add(self, entries):
        """Append entries not seen before. Ordering keys are assigned here, on first
        observation, which is the only monotonic signal that involves no clock."""
        if not entries:
            return 0

        fresh = [
            e for e in entries
            if not self.manifest.already_indexed(e.source, e.session, e.n)
        ]
        if not fresh:
            return 0

        source = fresh[0].source
        seq = self.manifest.next_seq(source, len(fresh))
        observed = datetime.now(timezone.utc)

        written = 0
        for entry in fresh:
            path = self.local_path(source, entry.hour)
            path.parent.mkdir(parents=True, exist_ok=True)
            if not path.exists():
                path.write_text(self.HEADER)

            with path.open('a') as fh:
                if entry.discontinuity:
                    fh.write('#EXT-X-DISCONTINUITY\n')
                    fh.write(f'#EXT-X-ARCHIVE-SESSION:{entry.session}\n')
                fh.write(f'#EXT-X-ARCHIVE-SEQ:{seq}\n')
                # Our own wall clock at the moment this segment was first seen
                # complete, independent of the PDT FFmpeg derived from the
                # publisher's timeline.
                #
                # PDT is anchored once at session start and then advances with the
                # incoming stream, so it tracks the publisher's crystal rather than
                # real time. Two independent clocks at typical +/-50ppm tolerance can
                # separate by several seconds a day, and a con-long session never
                # reconnects to re-anchor. Recording observed time costs ~45 bytes an
                # entry in a file that is read once per cut, and it means the drift
                # can be measured and corrected after the fact instead of having to
                # be known in advance.
                fh.write(
                    '#EXT-X-ARCHIVE-OBSERVED:'
                    + observed.strftime('%Y-%m-%dT%H:%M:%S.')
                    + f'{observed.microsecond // 1000:03d}+0000\n'
                )
                fh.write(f'#EXTINF:{entry.duration:.6f},\n')
                fh.write(
                    '#EXT-X-PROGRAM-DATE-TIME:'
                    + entry.pdt.strftime('%Y-%m-%dT%H:%M:%S.')
                    + f'{entry.pdt.microsecond // 1000:03d}+0000\n'
                )
                fh.write(entry.generic_name() + '\n')

            self.manifest.record_indexed(source, entry.session, entry.n, seq, entry.hour)
            with self.lock:
                self.dirty.add((source, entry.hour, 'index.m3u8'))
            seq += 1
            written += 1

        return written

    def add_source(self, entries, directory):
        """
        Same job as add(), for the archive-only source rendition.

        Kept separate rather than folded into add() because the two disagree on the
        one thing add() relies on: the ladder's renditions are cut at identical
        instants so a single entry with %v describes all three, while the source
        rendition is cut wherever the publisher put a keyframe. Merging them would
        mean an index entry that is right for three renditions and wrong for the
        fourth.

        Consequences of the split, both deliberate:
          * Its own ordering namespace (`{source}#source`), so seq numbers and the
            (session, n) dedupe key cannot collide with the ladder's.
          * Segment size is recorded as #EXT-X-ARCHIVE-BYTES. The ladder's bitrates
            are known constants; the publisher's is whatever they sent, and the
            master playlist has to advertise a BANDWIDTH for it.
        """
        if not entries:
            return 0

        namespace = f'{entries[0].source}#{SOURCE_RENDITION}'

        fresh = [
            e for e in entries
            if not self.manifest.already_indexed(namespace, e.session, e.n)
        ]
        if not fresh:
            return 0

        source = fresh[0].source
        seq = self.manifest.next_seq(namespace, len(fresh))
        observed = datetime.now(timezone.utc)

        written = 0
        for entry in fresh:
            path = self.local_path(source, entry.hour, 'index-source.m3u8')
            path.parent.mkdir(parents=True, exist_ok=True)
            if not path.exists():
                path.write_text(self.SOURCE_HEADER)

            try:
                size = (Path(directory) / entry.name).stat().st_size
            except OSError:
                size = 0

            with path.open('a') as fh:
                if entry.discontinuity:
                    fh.write('#EXT-X-DISCONTINUITY\n')
                    fh.write(f'#EXT-X-ARCHIVE-SESSION:{entry.session}\n')
                fh.write(f'#EXT-X-ARCHIVE-SEQ:{seq}\n')
                fh.write(
                    '#EXT-X-ARCHIVE-OBSERVED:'
                    + observed.strftime('%Y-%m-%dT%H:%M:%S.')
                    + f'{observed.microsecond // 1000:03d}+0000\n'
                )
                if size:
                    fh.write(f'#EXT-X-ARCHIVE-BYTES:{size}\n')
                fh.write(f'#EXTINF:{entry.duration:.6f},\n')
                fh.write(
                    '#EXT-X-PROGRAM-DATE-TIME:'
                    + entry.pdt.strftime('%Y-%m-%dT%H:%M:%S.')
                    + f'{entry.pdt.microsecond // 1000:03d}+0000\n'
                )
                # Concrete name, not %v: this entry describes exactly one rendition.
                fh.write(entry.name + '\n')

            self.manifest.record_indexed(namespace, entry.session, entry.n, seq, entry.hour)
            with self.lock:
                self.dirty.add((source, entry.hour, 'index-source.m3u8'))
            seq += 1
            written += 1

        return written

    def flush(self):
        """Push changed hour indexes to S3. The in-progress hour is re-uploaded
        whole; it is a few tens of KB, so a delta protocol would not pay for itself."""
        with self.lock:
            pending = list(self.dirty)
            self.dirty.clear()

        for source, hour, name in pending:
            path = self.local_path(source, hour, name)
            if not path.exists():
                continue
            try:
                s3.put_object(
                    Bucket=S3_BUCKET,
                    Key=self.s3_key(source, hour, name),
                    Body=path.read_bytes(),
                    ContentType='application/vnd.apple.mpegurl',
                )
            except ClientError as exc:
                logger.error('Index upload failed for %s/%s/%s: %s', source, hour, name, exc)
                # Put it back so the next flush retries.
                with self.lock:
                    self.dirty.add((source, hour, name))


def assert_renditions_aligned(source, canonical_entries, playlists):
    """
    One index entry describes all three renditions, which only holds while FFmpeg
    cuts them at identical instants. That is what -force_key_frames buys, but if it
    ever stops holding the index would mislabel two renditions out of three and
    nothing downstream would notice. So check rather than assume.
    """
    expected = {(e.session, e.n) for e in canonical_entries}
    for rendition, entries in playlists.items():
        if rendition == CANONICAL_RENDITION:
            continue
        got = {(e.session, e.n) for e in entries}
        missing = expected - got
        if len(missing) > 2:  # 1-2 in flight is normal skew between renditions
            logger.error(
                'Rendition %s of %s is out of step with %s: %d segments differ. '
                'Index entries assume identical boundaries across renditions.',
                rendition, source, CANONICAL_RENDITION, len(missing),
            )


# --------------------------------------------------------------------- uploading

class RateLimitedReader:
    """
    Token bucket over the read side of an upload.

    The origin uploads roughly 1.4 MB/s per source continuously while also feeding
    the edge, so unbounded archive traffic competes with viewers for the same uplink.
    boto3 takes any file-like object, so throttling reads throttles the transfer.
    """

    def __init__(self, fh, rate_bytes_per_sec):
        self.fh = fh
        self.rate = rate_bytes_per_sec

    def read(self, size=-1):
        chunk = self.fh.read(size)
        if chunk and self.rate > 0:
            self._consume(len(chunk))
        return chunk

    def _consume(self, count):
        with rate_limiter_lock:
            now = time.monotonic()
            elapsed = now - _rate_tokens['at']
            _rate_tokens['at'] = now
            _rate_tokens['bytes'] = max(0.0, _rate_tokens['bytes'] - elapsed * self.rate)
            _rate_tokens['bytes'] += count
            over = _rate_tokens['bytes'] - self.rate  # allow one second of burst
            delay = over / self.rate if over > 0 else 0
        if delay > 0:
            time.sleep(min(delay, 5))

    def __getattr__(self, name):
        return getattr(self.fh, name)


def upload_segment(manifest, path, key):
    """Upload then confirm. Only a confirmed copy makes a segment reapable."""
    with upload_semaphore:
        try:
            local = Path(path)
            if not local.exists():
                # Reaped or removed underneath us; nothing to do.
                manifest.forget(path)
                return

            size = local.stat().st_size
            if size == 0:
                return

            rate = MAX_UPLOAD_RATE_MBPS * 1_000_000 / 8
            with local.open('rb') as fh:
                body = RateLimitedReader(fh, rate) if rate > 0 else fh
                s3.put_object(
                    Bucket=S3_BUCKET,
                    Key=key,
                    Body=body,
                    ContentType='video/mp2t',
                )

            head = s3.head_object(Bucket=S3_BUCKET, Key=key)
            if head['ContentLength'] != size:
                logger.error(
                    'Size mismatch for %s: local %d, remote %d. Not marking verified.',
                    key, size, head['ContentLength'],
                )
                with metrics_lock:
                    metrics['failed'] += 1
                return

            manifest.record_verified(path, size, head.get('ETag', '').strip('"'))
            with metrics_lock:
                metrics['uploaded'] += 1
                metrics['verified'] += 1

        except ClientError as exc:
            logger.error('Upload failed for %s: %s', key, exc)
            with metrics_lock:
                metrics['failed'] += 1
        except OSError as exc:
            logger.error('Read failed for %s: %s', path, exc)
            with metrics_lock:
                metrics['failed'] += 1


# ------------------------------------------------------------------------ reaper

def reap(manifest):
    """
    Delete local segments that S3 has confirmed and that have left the rewind window.

    Both conditions are required. Verification alone is not enough: the segments
    inside the window are what makes live rewind work, and they are also what the
    transcoder's session-collision check reads. Age alone is obviously not enough.
    """
    now = time.time()
    freed = 0

    for path, key, size in manifest.reapable():
        local = Path(path)
        if not local.exists():
            manifest.forget(path)
            continue

        try:
            age = now - local.stat().st_mtime
        except OSError:
            continue

        if age < DVR_WINDOW_SECONDS:
            continue

        # Re-confirm against S3 rather than trusting the manifest alone; a bucket
        # lifecycle rule or an out-of-band delete would otherwise go unnoticed.
        try:
            head = s3.head_object(Bucket=S3_BUCKET, Key=key)
        except ClientError:
            logger.warning('Not in S3 at reap time, keeping local copy: %s', key)
            continue

        if size is not None and head['ContentLength'] != size:
            logger.warning('Size drift at reap time, keeping local copy: %s', key)
            continue

        try:
            local.unlink()
            manifest.forget(path)
            freed += 1
        except OSError as exc:
            logger.error('Could not delete %s: %s', path, exc)

    if freed:
        with metrics_lock:
            metrics['reaped'] += freed
        logger.info('Reaped %d verified segment(s) past the %ds window',
                    freed, DVR_WINDOW_SECONDS)


# -------------------------------------------------------------------- main sweep

def sweep(manifest, indexer):
    """One reconciling pass: parse playlists, index and enqueue what is complete."""
    for source in discover_sources():
        playlists = {}
        for rendition in RENDITIONS:
            path = Path(HLS_PATH) / f'{source}_{rendition}.m3u8'
            if path.exists():
                entries, complete = parse_playlist(path)
                playlists[rendition] = entries[:complete]

        canonical = playlists.get(CANONICAL_RENDITION)
        if not canonical:
            continue

        assert_renditions_aligned(source, canonical, playlists)

        written = indexer.add(canonical)
        if written:
            with metrics_lock:
                metrics['indexed'] += written

        # Every rendition's bytes still have to be uploaded individually, even though
        # one index entry covers all of them.
        for rendition, entries in playlists.items():
            for entry in entries:
                path = Path(HLS_PATH) / entry.name
                if manifest.known(path):
                    continue
                key = f'{ARCHIVE_PREFIX}/{source}/{entry.hour}/{entry.name}'
                manifest.record_pending(path, key, source)

        sweep_source(manifest, indexer, source)

    dispatch_uploads(manifest)


def sweep_source(manifest, indexer, source):
    """
    The archive-only source rendition, from its own directory and its own index.

    Segments land in the same hour prefix as the ladder's - the filename already
    carries `_source_`, so nothing collides - and only the index is separate.
    """
    if not ARCHIVE_SOURCE:
        return

    path = Path(SOURCE_HLS_PATH) / f'{source}_{SOURCE_RENDITION}.m3u8'
    if not path.exists():
        return

    entries, complete = parse_playlist(path)
    entries = entries[:complete]
    if not entries:
        return

    written = indexer.add_source(entries, SOURCE_HLS_PATH)
    if written:
        with metrics_lock:
            metrics['indexed'] += written

    for entry in entries:
        local = Path(SOURCE_HLS_PATH) / entry.name
        if manifest.known(local):
            continue
        manifest.record_pending(
            local, f'{ARCHIVE_PREFIX}/{source}/{entry.hour}/{entry.name}', source
        )


def dispatch_uploads(manifest):
    """
    Hand pending segments to the upload workers, at most once each.

    A row stays in pending_uploads until its upload finishes and verifies, and the
    sweep runs every few seconds, so spawning a thread per pending row per sweep
    both re-uploaded segments already in flight and grew the thread count without
    bound whenever S3 was slower than the sweep interval - which is exactly the
    situation the upload cap is designed to produce. The semaphore bounded
    concurrency but not the number of threads waiting on it.

    `inflight` is the missing piece: it is the set of paths some worker still owns.
    """
    for path, key, _ in manifest.pending_uploads():
        with inflight_lock:
            if path in inflight:
                continue
            inflight.add(path)

        threading.Thread(
            target=_upload_and_release, args=(manifest, path, key), daemon=True
        ).start()


def _upload_and_release(manifest, path, key):
    try:
        upload_segment(manifest, path, key)
    finally:
        with inflight_lock:
            inflight.discard(path)


def periodic(interval, fn, *args):
    while True:
        time.sleep(interval)
        try:
            fn(*args)
        except Exception:
            logger.exception('%s failed', getattr(fn, '__name__', fn))


def report(manifest):
    """
    Metrics, plus a standing check that the upload cap is above the ingest rate.

    Setting MAX_UPLOAD_RATE_MBPS below what the transcoder produces does not degrade
    gracefully: uploads simply fall behind for hours and then the origin disk fills.
    The symptom appears a long way from the cause, so watch the backlog trend and say
    so early. A rising backlog across several minutes means the cap (or the link) is
    under the ingest rate, not that a single upload was slow.
    """
    history = []

    while True:
        time.sleep(60)

        pending = manifest.pending_count()
        history.append(pending)
        history = history[-5:]

        with metrics_lock:
            logger.info(
                'indexed=%d uploaded=%d verified=%d reaped=%d failed=%d pending=%d',
                metrics['indexed'], metrics['uploaded'], metrics['verified'],
                metrics['reaped'], metrics['failed'], pending,
            )

        if len(history) == 5 and all(b < a for b, a in zip(history, history[1:])):
            sources = len(discover_sources()) or 1
            # Measured ladder total, Mbps per source. With ARCHIVE_SOURCE on, the
            # publisher's own bitrate rides on top and is not knowable from here, so
            # this is a floor on the floor rather than the real requirement.
            needed = sources * 11.5
            logger.error(
                'Upload backlog has grown for %d minutes straight (%s). The archive '
                'is not keeping up with ingest and the origin disk will fill. %d '
                'source(s) need at least ~%.0f Mbps sustained (plus the contribution '
                'bitrate again, per source, while the source archive is on); cap is %s.',
                len(history), ' -> '.join(str(h) for h in history), sources, needed,
                f'{MAX_UPLOAD_RATE_MBPS} Mbps' if MAX_UPLOAD_RATE_MBPS > 0
                else 'unlimited (so the link itself is the limit)',
            )


def main():
    logger.info('HLS archive uploader starting')
    logger.info('Watching %s, archiving to s3://%s/%s', HLS_PATH, S3_BUCKET, ARCHIVE_PREFIX)
    logger.info('Renditions: %s (canonical %s)', ','.join(RENDITIONS), CANONICAL_RENDITION)
    logger.info(
        'Source archive: %s',
        f'on, from {SOURCE_HLS_PATH} into index-source.m3u8' if ARCHIVE_SOURCE else 'off',
    )
    logger.info('Rewind window held locally: %ds', DVR_WINDOW_SECONDS)
    logger.info(
        'Upload cap: %s',
        f'{MAX_UPLOAD_RATE_MBPS} Mbps' if MAX_UPLOAD_RATE_MBPS > 0 else 'unlimited',
    )

    Path(INDEX_PATH).mkdir(parents=True, exist_ok=True)
    manifest = Manifest(MANIFEST_DB)
    indexer = Indexer(manifest)

    try:
        s3.head_bucket(Bucket=S3_BUCKET)
        logger.info('Connected to bucket %s', S3_BUCKET)
    except ClientError as exc:
        # Not fatal: the bucket may appear later, and segments accumulate on disk
        # meanwhile rather than being lost.
        logger.error('Bucket %s not reachable yet: %s', S3_BUCKET, exc)

    threading.Thread(target=report, args=(manifest,), daemon=True).start()
    threading.Thread(
        target=periodic, args=(INDEX_UPLOAD_INTERVAL, indexer.flush), daemon=True
    ).start()
    threading.Thread(
        target=periodic, args=(REAP_INTERVAL, reap, manifest), daemon=True
    ).start()

    while True:
        try:
            sweep(manifest, indexer)
        except Exception:
            logger.exception('Sweep failed')
        time.sleep(SWEEP_INTERVAL)


if __name__ == '__main__':
    main()
