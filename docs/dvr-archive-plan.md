# DVR archive redesign

Replaces the SRS-MP4 -> concat -> trim -> re-encode pipeline with a segment-first
archive built from the HLS the transcoder already produces.

Companion to `docs/streaming-auth-redesign.md`: archive playback runs over the same
edge and the same playback token, with no special casing.

## Goals

1. 60 minutes of live rewind so a late viewer can jump back to the start of a panel.
2. Reliable, resumable sync of every segment to the recordings S3, addressed by show slug.
3. Preview and trim recordings in the `/manage` panel.
4. Recording never stops for the duration of the con, and origin disk is released as
   soon as S3 has a verified copy.

## What exists today

| Piece | File | Role |
|---|---|---|
| Transcoder | `docker/ffmpeg-hls/stream-manager.sh` | ABR ladder plus the source mirror, 2s segments, 60 minute sliding window |
| Archive uploader | `docker/dvr-uploader/archive_uploader.py` | index, upload, verify, reap |
| Cut | `app/Services/ArchivePlaylistService.php` | PDT range over the hour indexes, rendered per request |
| SRS DVR | `docker/origin-srs/origin.conf` | writes segmented MP4 to `/dvr/recordings`; cold backup only |
| MP4 uploader | `docker/dvr-uploader/uploader.py` | watchdog on `.mp4`/`.flv`, upload, delete; serves the cold backup |
| Model | `app/Models/Recording.php` | cut markers, `archive_prefix`, `status`, `segment_count` |
| Disks | `config/filesystems.php` | `s3` (app assets, thumbnails) and `dvr` (recordings bucket) |

### Why the old MP4 cuts were rough

Kept here because it is the argument for never going back to them. The pipeline
itself is gone: `dvr-extract.sh`, `dvr-process.sh`, `dvr-convert.sh`,
`DvrExtractorService` and the `dvr:extract` command were deleted once cuts came from
the segment archive.

- SRS writes each `dvr_duration` chunk with its own timestamp base. `concat` with
  `-c copy` splices those bases, so every chunk boundary was a timestamp discontinuity.
- `-ss` after `-i` with `-c copy` keeps original timestamps and starts at the nearest
  preceding keyframe, which gave a frozen head and A/V skew.
- `dvr_wait_keyframe` aligns video only. AAC priming at each splice accumulated drift.
- The copy -> re-encode -> `filter_complex concat` fallback chain meant recordings
  were not all processed the same way.
- Everything was encoded twice: the live ladder, then a second ladder for the cut.

## Target design

The transcoder already emits exactly what a VOD archive wants:

```
-force_key_frames "expr:gte(t,n_forced*2)" -g 60 -keyint_min 60 -sc_threshold 0
-hls_time 2 -hls_flags independent_segments+program_date_time+discont_start
```

Every segment is independently decodable, boundaries are identical across `sd`/`hd`/`fhd`,
and `EXT-X-PROGRAM-DATE-TIME` anchors the timeline to wall clock. So:

- **Trimming is playlist authoring.** Select a sub-range of segments, write a VOD
  playlist. No decode, no concat, no `-ss`. Seams cannot exist because nothing is ever
  cut inside a segment.
- **Cuts are non-destructive and re-editable.** A `Recording` is a `(archive prefix,
  start PDT, end PDT)` view. Re-cutting rewrites a text file.
- **ABR survives into the archive** and the second encode pass disappears.

### 1. Origin: DVR window and non-destructive session dirs

Changes to `docker/ffmpeg-hls/stream-manager.sh`:

```diff
-        -hls_list_size 60 \
-        -hls_delete_threshold 60 \
+        -hls_list_size "$DVR_WINDOW_SEGMENTS" \
+        -hls_delete_threshold "$HLS_DELETE_THRESHOLD" \
-        -hls_segment_filename "$output_dir/${stream}_%v_${timestamp_prefix}_%05d.ts" \
+        -hls_segment_filename "$output_dir/${stream}_%v_${timestamp_prefix}_%06d.ts" \
```

`hls_list_size 1800` at `hls_time 2` is a 60 minute seekable window, and
`hls_delete_threshold 60` keeps a further two minutes on disk after a segment leaves the
playlist. Step 1 ships on its own, before any uploader exists, so FFmpeg must still be
the thing deleting segments or the disk grows without bound. Step 3 raises the threshold
to effectively infinite and hands deletion to the uploader's verified reaper.

The output layout stays **flat**. Per-session subdirectories were considered and
rejected: origin nginx serves `root /var/www/hls` with `location ~ ^/live/(.+\.m3u8)$`,
and the app hands out a stable master URL (`/live/<stream>_master.m3u8`, see
`app/Models/User.php:119`). Session directories would move the master under a path that
changes on every reconnect, so keeping the URL stable would mean writing a second master
at the top level with rewritten variant paths. The segment filenames already carry
`${timestamp_prefix}`, which gives per-session uniqueness with none of that.

What replaces the session directories is an **orphan reaper**. FFmpeg's
`delete_segments` only removes files that the *current* session wrote, so a publisher
reconnect strands the previous session's segments forever. The manager sweeps
`OUTPUT_BASE_DIR` every 5 minutes and deletes `.ts` files older than
`ORPHAN_RETENTION_MINUTES`, which derives from the window plus a margin (67m at the
defaults) so it can never reach a segment the live playlist still references. Step 3
sets it to `0` once the uploader's verified reaper owns deletion.

Two destructive cleanups had to change, because with the archive living on disk they
would delete unuploaded material:

- `stop_ffmpeg` did `rm -f "$OUTPUT_BASE_DIR/${stream}"*`. That glob is outside the
  quotes, so it expanded and took every segment with it. Now restricted to the playlists.
- `start_ffmpeg` did `rm -f "$output_dir/${stream}_*.ts"`. That glob is *inside* the
  quotes, so it never expanded and the line was always inert. Removed rather than fixed.

Add a freshness watchdog to the monitor loop: if SRS reports the stream as publishing
but the stream's playlists have stopped advancing for 15s, restart ffmpeg. Today a wedged
ffmpeg that has not exited is invisible to `check_streams`, which only compares PIDs
against the SRS stream list. Playlist mtime is the liveness signal rather than segment
mtime, because it is one `stat` per rendition instead of a scan of thousands of files.

Bump `%05d` to `%06d`: 99999 segments is 55 hours, which a con-long stream reaches.

**Session id collisions.** `timestamp_prefix=$(date +%s)` has one-second resolution, so
two FFmpeg starts inside the same second produce the same prefix and the second session
writes `${stream}_hd_<same>_000000.ts` directly over the first session's segments. The 30s
startup grace keeps the watchdog off this path, but a fast crash-restart loop through
`check_streams` (5s interval) reaches it, and the failure is silent archive loss rather
than an error. `start_ffmpeg` now bumps the prefix while any segment already carries it.
The check works precisely because the orphan reaper leaves old sessions' segments on disk.

This matters beyond the transcoder: the segment filename is the archive's primary key, so
a collision corrupts S3 objects and index entries alike.

One more lifecycle bug surfaced while testing the watchdog. FFmpeg was launched as
`ffmpeg ... 2>&1 | sed "s/^/[FFmpeg $stream] /" &`, and for a backgrounded pipeline `$!`
is the PID of the *last* stage. Every stored PID was sed's, so `stop_ffmpeg` killed the
log prefixer and left FFmpeg encoding indefinitely; only the `pgrep -f` fallback in
`start_ffmpeg` ever cleaned one up, and then only if the same stream came back. A stream
that ended for good leaked its encoder. Replaced with process substitution
(`> >(sed ...) 2>&1 &`), which makes `$!` FFmpeg's own PID and lets the sed exit when the
pipe closes.

### 1b. Origin: the source rendition

The ladder tops out at 6 Mbps. A contribution feed is routinely two to three times
that, so everything above the fhd rung was being thrown away at ingest and could never
be recovered for an archive cut. The transcoder now mirrors the publisher's own
bitstream alongside the ladder:

```
-map 0:v -map 0:a -c copy -f hls ... "$SOURCE_OUTPUT_DIR/${stream}_source.m3u8"
```

Same FFmpeg process, second output. One process rather than a second RTMP pull, so SRS
sees one player and the two outputs cannot disagree about where the session started.
Remux only, so the cost is disk and upload bandwidth, not CPU.

**It is never served live.** Both nginx configs serve `root /var/www/hls` under
`location ~ ^/live/`, and the edge proxies any `/live/*.ts` behind a token that is
scoped to the source slug, not the rendition — so anything written next to the ladder
is fetchable by a viewer who guesses a filename. `SOURCE_OUTPUT_DIR` is therefore
`/var/www/hls/source`, a *sibling* of the ladder's directory: neither location block
matches it and the default `location /` returns 404. "Archived, never served" holds by
construction rather than by a deny rule someone has to remember to keep.

**It gets its own hour index.** `-c copy` can only split on a keyframe the publisher
already sent, so segment boundaries follow their GOP rather than the ladder's forced 2s
marks. The one-entry-describes-all-renditions trick that `index.m3u8` relies on is
exactly what does not hold here, so the source rendition is indexed separately in
`index-source.m3u8`, in its own `{source}#source` ordering namespace, with concrete
filenames instead of `%v`. Segments still land in the same hour prefix; the filename
already carries `_source_`, so nothing collides.

Each source entry also carries `#EXT-X-ARCHIVE-BYTES`. The ladder's bitrates are
constants the transcoder was told to hit; the publisher's is whatever the encoder on
the con floor was set to, and the master playlist has to advertise a `BANDWIDTH` for
it. Measuring it from the archive is the only honest option.

**Advertising it is opt-in** (`ARCHIVE_SOURCE_IN_MASTER`, default off). Putting a
17 Mbps rung in a recording's master means every viewer on a fast connection pulls the
full contribution bitrate out of S3, which is a bandwidth bill rather than a feature.
It is always playable explicitly at `/archive/{slug}/source.m3u8`, which is the path
for pulling a master to edit from, and the trim editor can preview it via
`?rendition=source`.

**Capacity changes.** Per source, at a 17 Mbps feed: the 60 minute window grows by
~7.6 GB on origin disk, the archive by ~7.6 GB/hour, and `MAX_UPLOAD_RATE_MBPS` must
clear `sources x (11.5 + contribution)` rather than `sources x 11.5`. 200 Mbps carried
8 sources at 2x headroom before; at 28.5 Mbps per source it carries 7 at none. Raise
it, or set `ARCHIVE_SOURCE: 0` on sources where the ladder is enough.

### 2. Indexing (part of the uploader, not a separate process)

**Not a sidecar.** The uploader has to parse the rendition playlists anyway, because its
completion rule is "a segment is done once it appears in the playlist and is not the last
entry". That parse already yields filename, `EXTINF`, PDT and discontinuity position.
Indexing is a few lines on data already in hand. A separate process would duplicate the
parse, need its own view of the volume, and introduce a failure mode where the uploader
and the indexer disagree about what they saw.

**The index is the playlist, persisted.** The live playlist holds the only record of when
a segment happened, and it forgets each entry after 60 minutes. Nothing more exotic than
that is going on, so the stored form is simply an HLS playlist per source per hour:

```
archive/{source}/{YYYYMMDD}/{HH}/index.m3u8
```

```
#EXTM3U
#EXT-X-VERSION:6
#EXT-X-TARGETDURATION:2
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-ARCHIVE-SESSION:1754156625
#EXT-X-ARCHIVE-SEQ:28800
#EXTINF:2.000000,
#EXT-X-PROGRAM-DATE-TIME:2026-08-02T16:00:00.000+0000
mainstage_%v_1754156625_000042.ts
```

No JSON, no bespoke schema, no format conversion. Generating a cut becomes: concatenate
the hour files spanning the range, drop the entries outside it, prepend a header, append
`ENDLIST`. The stored form is already the target form, and the admin editor parses HLS
regardless. `#EXT-X-DISCONTINUITY` is carried through verbatim rather than round-tripped
through a boolean.

`ARCHIVE-SEQ` and `ARCHIVE-SESSION` are custom tags. RFC 8216 requires clients to ignore
unrecognised `#EXT-X-` tags, so these travel harmlessly even if a playlist is served to a
player by accident.

`%v` is substituted per rendition. Segment boundaries are identical across `sd`/`hd`/`fhd`
(shared input, `-force_key_frames "expr:gte(t,n_forced*2)"`), so one entry describes all
three and the index stays a third of the size.

FFmpeg writes `EXT-X-PROGRAM-DATE-TIME` before *every* segment rather than once as an
anchor (verified against real output in the dev stack), so PDT is read directly and never
derived by accumulating `EXTINF`.

The in-progress hour is re-uploaded once a minute; the hour is finalised when it rolls over.

#### Three fields, three jobs

The temptation is to order the archive by `pdt`, since it is the field that means
something to a human. Do not. Each field has exactly one job:

| Field | Job | Clock-dependent |
|---|---|---|
| `seq` | **Ordering.** Indexer-assigned, global per source, monotonic, never reused | No |
| `session` + `n` | Provenance and dedupe key | Only via `session` |
| `pdt` | Display, and selecting cut points | Yes |

`seq` is assigned by the indexer at the moment it first observes a segment. Observation
order follows playlist order, which is inherently monotonic, so `seq` needs no clock and
cannot go backwards. A clock jump then costs cut *accuracy* and can never corrupt archive
*order*.

Some detail behind that, because the naive version of this rule is wrong:

- Within a session, `n` is FFmpeg's own counter (`-start_number 0`, +1 per segment) and
  involves no clock at all.
- `session` is `date +%s`, so it *is* clock-derived. "Order by session and n" therefore
  does not avoid the clock across session boundaries, which is why `seq` exists.
- PDT is more robust than it first appears: FFmpeg's HLS muxer anchors wall clock once at
  session start and then accumulates `EXTINF`, rather than re-reading the clock per
  segment. A mid-stream NTP correction does not corrupt it. Observed in the dev stack,
  consecutive PDTs were exactly 2.000s apart (`22:37:17.805` then `22:37:19.805`); clock
  sampling would show jitter.
- The flip side: PDT tracks the *encoder's* timeline from that one anchor, not real time,
  so it can drift from wall clock over a long session. Drift resets on every reconnect,
  which makes a con-long stream that never reconnects the worst case. **Unmeasured.**
  Worth measuring against a con-length run before trusting PDT for cut points; if drift is
  material, the indexer can re-anchor by stamping its own observation time periodically
  and interpolating.

#### Guard the identical-boundaries assumption

One index entry describing all three renditions relies on FFmpeg cutting them at the same
instants. That holds because of `-force_key_frames "expr:gte(t,n_forced*2)"` on a shared
input, but if it ever stops holding — `ABR_MODE=copy` with a publisher whose GOP does not
match `hls_time`, say — the index silently mislabels two renditions out of three, and
nothing downstream would notice.

So the indexer asserts it rather than assuming it: periodically compare segment count and
PDT across the three playlists, and log loudly on divergence. Cheap, and it converts a
silent correctness bug into a visible one.

### 3. S3 layout

Two prefixes on the existing `dvr` disk, with different lifetimes:

```
archive/{source_slug}/{YYYYMMDD}/{HH}/{stream}_{rendition}_{session}_{seq}.ts
archive/{source_slug}/{YYYYMMDD}/{HH}/index.json

recordings/{show_slug}/master.m3u8
recordings/{show_slug}/{rendition}.m3u8
recordings/{show_slug}/thumb.jpg
```

Segments are bucketed by the hour their *start* PDT falls in, so a segment straddling the
boundary belongs to the earlier hour and the index for hour H may describe a segment that
extends slightly into H+1. `{session}` is the ffmpeg session id, which keeps sequence
numbers unique across a publisher reconnect inside the same hour.

#### Why hour buckets rather than a flat prefix

Two conventions exist in the industry. Bounded VOD assets go flat, scoped by asset id:
Mux uses `{asset_id}/{rendition}/{seq}.ts`, and MediaLive's S3 output puts everything
under one prefix with the counter in the filename. Continuous DVR and timeshift go
time-bucketed: Flussonic DVR and Wowza nDVR both use `{stream}/{hour}/{segment}`, because
retention and range lookup are both by wall clock. A con-long stream is the second case.

Prefix depth itself costs nothing. S3 has no directories, and since 2018 it auto-partitions,
so the old random-prefix guidance no longer applies. At 5400 PUT/hour/source, or 1.5/s
against a 3500/s per-prefix ceiling, no layout is under pressure.

The deciding factor is reconciliation. The uploader's correctness rests on listing what S3
actually holds and diffing it against disk. Flat over a 5-day stream is ~648k objects, or
~648 `list_objects_v2` pages per full reconcile. Hour-bucketed, only the hours in play get
re-listed, around 6 pages each. Prefix-scoped lifecycle rules (retain the days holding
published recordings, expire the rest) are a secondary benefit; expiry by object age works
without prefixes anyway.

A `{rendition}/` directory level is deliberately absent: the filename already carries the
rendition, so it would add a level and a LIST dimension for nothing.

The archive is keyed by source and wall-clock hour, not by show. This is what lets a
single continuous main stream span the whole con while still producing many recordings:
a `Recording` is a PDT range over the archive, and the generated playlist under
`recordings/{show_slug}/` points into `archive/`. Nothing is copied when you cut.

Show slug addressing (the requirement) lives at the `recordings/` prefix, which is the
layer users and the player see. `Show::boot()` already generates
`Str::slug($title . '-' . $scheduled_start->format('Y-m-d'))`, so slugs are stable and unique.

### 4. Uploader rewrite

`docker/dvr-uploader/uploader.py` today matches `.mp4`/`.flv` only, watches
`/dvr/recordings`, and decides a file is finished by polling its size twice two seconds
apart. All three need to change.

**Completion rule.** A segment is complete when it appears in the rendition playlist and
is not the last entry. That is exact and race-free; drop the size-polling heuristic.

**Reconciling sweeper, not just a watcher.** inotify is the fast path; correctness comes
from a sweep every 30s that diffs local segments against a local manifest
(`sqlite`, one row per key: `path, key, size, etag, uploaded_at, verified_at`). Restart
after a crash rebuilds by re-listing the affected hour prefixes.

**Verify before delete.** After upload, `head_object` the key and compare size and ETag.
Only a verified match sets `verified_at`.

**Reaper.** Separate thread. Deletes a local segment only when both hold:

1. `verified_at` is set, and a periodic `list_objects_v2` over the hour prefix still
   confirms the key with the right size.
2. The segment is older than `DVR_WINDOW_SECONDS` (default 3600), so deletion never eats
   into the live rewind window.

**Backpressure.** If free disk drops below a threshold, log at error level, emit the
condition to the metrics endpoint, and shrink the effective DVR window before touching
anything unverified. Never delete an unverified segment silently.

Keep `MAX_CONCURRENT_UPLOADS` and the semaphore, but retune `TransferConfig`: the 100MB
multipart threshold is sized for 800MB MP4s and is irrelevant for ~3MB segments. Small
files want more concurrency and no multipart.

### 5. VOD playlist generation

New `App\Services\ArchivePlaylistService`:

- `index(string $source, CarbonInterval $range): Collection` reads and merges the hour
  `index.json` shards covering the range.
- `build(Recording $recording): void` selects segments whose derived PDT falls in
  `[starts_at, ends_at]`, writes one media playlist per rendition plus a master, and
  puts them at `recordings/{show_slug}/`.

Generated media playlist:

```
#EXTM3U
#EXT-X-VERSION:6
#EXT-X-PLAYLIST-TYPE:VOD
#EXT-X-TARGETDURATION:2
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-PROGRAM-DATE-TIME:2026-08-02T16:43:46.000Z
#EXTINF:2.000000,
/archive/mainstage/20260802/16/mainstage_hd_1754156625_00042.ts
...
#EXT-X-ENDLIST
```

`PLAYLIST-TYPE:VOD` and `ENDLIST` are mandatory; without them players treat the archive
as live. Discontinuity flags from the index become `#EXT-X-DISCONTINUITY`.

`RecordingService::extractDurationFromM3u8()` already sums `EXTINF` and follows master
playlists, so duration comes for free. Thumbnails keep working: `captureFrameAtTime`
against the generated VOD playlist.

One thing that is *not* free, and was broken for a while: a cut's `m3u8_url` is an app
route, because playlists are rendered per request. That route needs a session and a
queue worker has none, so ffmpeg fetching it got 404 on an unpublished draft — which is
every cut at the moment the observer first dispatches the job. `RecordingService` now
renders the playlist to a temp file and points ffmpeg at that; the segment URLs inside
are absolute and signed, so it still fetches the media itself.

`DvrExtractorService`, `dvr-extract.sh`, `dvr-process.sh`, `dvr-convert.sh` and
`ExtractDvrSegments` are **deleted**. The SRS `dvr` block stays until after the next
event; see Sequencing.

### 6. Manage UI: preview and trim

New module under `/manage`, following the existing `Sources`/`Shows` shape in
`routes/manage.php` and `resources/js/Pages/Manage/`.

```php
Route::get('recordings',                    [RecordingController::class, 'index'])->name('recordings.index');
Route::get('recordings/{recording}',        [RecordingController::class, 'edit'])->name('recordings.edit');
Route::put('recordings/{recording}',        [RecordingController::class, 'update'])->name('recordings.update');
Route::post('recordings/{recording}/cut',   [RecordingController::class, 'cut'])->name('recordings.cut');
Route::post('recordings/{recording}/publish', [RecordingController::class, 'publish'])->name('recordings.publish');
Route::post('shows/{show}/recordings',      [RecordingController::class, 'store'])->name('recordings.store');
```

`Manage/Recordings/Index.vue` — list with show, source, date, duration, size, status
(`recording` / `ready` / `published`), reusing the existing table-column preferences
(`TableColumnController`).

`Manage/Recordings/Editor.vue` — the trim screen:

- `VideoPlayer` on a *preview* playlist covering the whole archive range plus padding, so
  the operator can find the real start rather than being locked to the current cut.
- Scrubber with in/out handles, snapped to the 2s segment grid. Handle positions display
  as both offset and absolute PDT.
- `[` / `]` set in/out at playhead; `J`/`K`/`L` shuttle; arrow keys nudge one segment.
- Discontinuities drawn as ticks on the scrubber, since those are the points a cut is
  most likely to be wanted.
- Save regenerates the playlists and updates `starts_at`/`ends_at`. Non-destructive, so
  it can be redone any number of times while the archive segments are retained.
- "Split here" creates a second `Recording` over the same archive from the playhead.
  This is the workflow for slicing individual panels out of the con-long main stream.

Frame-accurate starts are out of scope: granularity is one segment, 2s. If a specific
recording needs tighter, re-encode only the two boundary segments and leave the rest
untouched.

### 7. Player: live rewind

**Done.** `resources/js/Components/Player/VideoPlayer.vue` (renamed from `EfPlayer.vue`
during branding neutralisation) already supported `liveStreamType: 'live:dvr'` and
`seekToLive()`, but nothing ever passed `live:dvr`, so the seek bar never appeared and the
window would have stayed invisible no matter how large it was. `StreamPlayer.vue` now
defaults the prop to `'live:dvr'` and forwards it, leaving `'live'` available per-caller
for a source that keeps no window.

`backBufferLength` is now derived from stream type: 30s for plain live, 90s for `live:dvr`
and on-demand. `-1` was rejected because it would hold the whole hour in memory; 90s
covers a short "what did they just say" rewind without a refetch.
`liveSyncDurationCount: 3` stays.

`StageHero.vue` and `ShowTile.vue` build their own hls.js instances for muted preview
tiles and were deliberately left at their small buffers.

Client-side parse cost is worth watching: hls.js re-parses the full 1800-entry playlist
on every 2s refresh. Fine on desktop, measurable on weak devices, so check the VRChat
and embed paths before rolling the window out to them.

### 8. Edge

Measured against real transcoder output (103 B per entry, since ffmpeg writes a
`PROGRAM-DATE-TIME` line per segment), an 1800-entry playlist is **179 KB raw and 10 KB at
`gzip -6`** — 5%, because the timestamp and filename sequences are almost perfectly
regular. Refreshed every 2s that is ~41 kbps per viewer, or ~82 Mbps of playlist traffic
at 2000 viewers. Uncompressed it would be ~716 Mbps, so compression is doing real work
here.

`application/vnd.apple.mpegurl` is **already** in `gzip_types` in all three configs
(`docker/edge-nginx/nginx.conf:43`, the `edge/nginx-config.blade.php` mirror, and
`docker/origin-nginx/nginx.conf:30`), so nothing is needed here.

`video/mp2t` was also in those `gzip_types` lists. MPEG-TS is already compressed, so with
`gzip_proxied any` the edge was spending level-6 gzip CPU on every video segment for no
size gain. **Removed** from all five configs (`docker/edge-nginx`, `docker/origin-nginx`,
`docker/dev/edge-nginx.conf`, `docker/dev/origin-nginx.conf`, and the edge provisioning
blade). Verified against the running stack: the playlist comes back
`Content-Encoding: gzip`, the segment comes back with a plain `Content-Length` and no
encoding.

The 2s `proxy_cache_valid` plus `proxy_cache_lock` already means origin sees one playlist
fetch per 2s regardless of viewer count; only edge egress scales.

Archive playback adds one location block for `/archive/` and `/recordings/`, proxying S3,
behind the same `auth_request /auth` as `/live/`. Segment caching there can be far more
aggressive than live: the objects are immutable.

## Data model

```php
Schema::table('recordings', function (Blueprint $table) {
    $table->foreignId('source_id')->nullable()->after('show_id');
    $table->string('archive_prefix')->nullable();      // archive/mainstage
    $table->timestampTz('starts_at')->nullable();      // cut in point, PDT
    $table->timestampTz('ends_at')->nullable();        // cut out point, PDT
    $table->timestampTz('archive_starts_at')->nullable(); // full available range
    $table->timestampTz('archive_ends_at')->nullable();
    $table->string('status')->default('pending');      // pending|recording|ready|published|failed
    $table->timestampTz('playlist_generated_at')->nullable();
    $table->unsignedBigInteger('size_bytes')->nullable();
    $table->unsignedInteger('segment_count')->nullable();
});
```

Optional `archive_hours` table (one row per source/hour: `source_id, hour, segment_count,
size_bytes, first_pdt, last_pdt, complete`) purely so the admin list does not have to read
S3. Individual segments stay out of Postgres; `index.json` in S3 is the source of truth.

## Capacity

Ladder totals 1500+3500+6000 kbps video and 128+160+192 kbps audio, about 11.5 Mbps, or
1.44 MB/s per source across all three renditions.

| Quantity | Value |
|---|---|
| 60 min DVR window on disk, per source | ~5.2 GB, 5400 files |
| Archive per hour, per source | ~5.2 GB, 5400 objects |
| Archive for a 5-day continuous stream | ~620 GB, ~648k objects |
| Live playlist size at 1800 entries | 179 KB raw, 10 KB gzipped (measured) |
| Playlist traffic per viewer | ~41 kbps |

620 GB for the main stream is the number worth a decision (see open questions). Origin
disk should be sized at 50 GB or more per source: the window itself is 5.2 GB, and the
rest is headroom for upload lag and for an S3 outage that stalls the reaper.

## Failure modes

| Failure | Handling |
|---|---|
| S3 unreachable | Segments accumulate on disk; reaper blocks on `verified_at`; disk watchdog alerts and degrades the window before anything unverified is dropped |
| Publisher reconnect | `discont_start`, indexer records the discontinuity, generated playlists emit `EXT-X-DISCONTINUITY` |
| ffmpeg wedged but alive | Playlist freshness watchdog restarts it |
| ffmpeg restart | Session prefix in every filename, collision-checked at start; `stop_ffmpeg` no longer wipes segments |
| Two restarts in one second | `start_ffmpeg` bumps the session prefix while it is already in use |
| Uploader crash | sqlite manifest plus prefix re-listing on boot; uploads are idempotent by key |
| Indexer gap | Index is rebuilt from the live playlist, which holds 60 minutes of history, so an outage shorter than the window loses nothing |
| Segment counter wrap | `%06d` (55h at 2s) plus a fresh session prefix on every restart |
| Origin disk full | Watchdog degrades the DVR window, then alerts loudly; never deletes unverified |
| Bad cut | Non-destructive; re-cut from the retained archive |

## Sequencing

1. **Done.** Origin changes in `docker/ffmpeg-hls/stream-manager.sh`: `hls_list_size 1800`,
   `%06d`, scoped `stop_ffmpeg` cleanup, orphan reaper, stall watchdog, and the `$!`
   process-substitution fix. Compose knobs in `docker-compose.dev.yml` (5 min window
   locally) and the origin provisioning blade (60 min). Ships the 60 minute rewind alone.
2. **Done.** PDT drift measured and designed around: index entries carry both `pdt` and
   `#EXT-X-ARCHIVE-OBSERVED`, so drift is correctable after the fact.
3. Uploader rewrite, indexing included: mount `hls-content`, `.ts` support, playlist-based
   completion, manifest, verified reaper, upload throttle, hour-playlist index.
4. `ArchivePlaylistService` plus schema migration; generate VOD playlists for one show
   end to end.
5. ~~Edge: `/archive/` and `/recordings/` locations behind the playback token.~~ **Dropped.**
   Recordings are ordinary VOD: there is no live capacity to spread and nothing on the
   media path the edge would protect. Playlists are rendered by the app per request and
   segments are handed out as presigned URLs (`ARCHIVE_URL_TTL`, 24h by default).

   A public bucket was considered and rejected. `archive/` holds the raw continuous
   capture of every source, including material never published and everything an operator
   trimmed off, so public reads would expose far more than the published output. Signing
   keeps the bucket private without a second delivery tier.

   Two consequences worth stating, because they are not obvious:

   - **Playlists cannot be stored.** A signed URL expires, so a playlist written to S3
     would be dead a day later. They are generated per request instead, which also puts
     the access check on the request that mints the URLs. That is the only point at which
     `required_roles` can actually be enforced.
   - **No copying.** An earlier draft copied a cut's segments into a public prefix. With
     signing there is no privacy reason left, and the remaining reason (archive expiry
     breaking a published recording) is better solved by making retention
     recording-aware: never expire a segment a published recording references. Zero
     duplication instead of a second copy of everything published.

   Cost: a signed URL runs ~500 characters, so a 15 minute cut is ~201 KB of playlist
   (22 KB gzipped) and an hour is roughly four times that. Acceptable for VOD, which
   fetches the playlist once rather than every two seconds like live.
6. Manage UI: index, then the trim editor.
7. **Done.** Player: `live:dvr` wired through `StreamPlayer.vue`, stream-type-aware
   `backBufferLength`.
8. **Half done.** `DvrExtractorService`, `dvr-extract.sh`, `dvr-process.sh`,
   `dvr-convert.sh` and `ExtractDvrSegments` are deleted: nothing referenced them but
   each other, and cuts have come from the segment archive since step 4.

   The SRS `dvr` block and `uploader.py` stay. Keep the MP4 DVR running as a cold
   backup through the next event; it is the fallback if the segment archive misses
   something. Note that it is no longer free now that `ARCHIVE_SOURCE` is on: origin
   disk carries the ladder window, the source window and the MP4 chunks at once.
   Retiring it is the first thing to do after the event.

Deleted alongside those, as stale rather than as part of this plan: repo-root
`origin.conf`, `custom.conf` and `nginx-hls-proxy.conf`,
`docker/origin-srs/origin.old.conf`, `config/nginx-hls-auth.conf`, the two placeholder
blades under `server-provisioning/common/`, `RecordingService::getFirstSegmentUrl()`,
the unread `stream.qualities` config, and `User::getUserStreamUrls()` with the
`hlsUrls` payload on `ServerAssignmentChanged`.

Still standing, and worth a decision: `Api\ServerFileController` (`/api/file/{file}`)
is a second, older provisioning path that is still routed. The `origin-conf` view it
serves configures SRS-side transcoding and RTMP fan-out, which contradicts the
passthrough + FFmpeg-ladder design this document describes.

### PDT drift: measured, then designed around

Every cut point is a PDT, and PDT is anchored once at session start and then advances with
the incoming stream, so it tracks the publisher's clock rather than real time.

**Measured in the dev stack:** 245 samples over 41 minutes, no re-anchor steps. Slope
-21.3 s/day (95% CI -35.0 to -7.6), PDT running ahead of wall clock, which extrapolates to
-106s over a 5-day con.

**That number should not be trusted.** The dev publisher runs
`-fflags +genpts -re -stream_loop -1` over a 20s clip (`docker/dev/publish.sh:102`), so it
crosses ~123 loop boundaries in that window, each one regenerating timestamps. A few
milliseconds lost per boundary accumulates to precisely the ~0.6s total shift observed. It
measures the dev publisher, not the HLS muxer.

**And no dev measurement can answer the real question.** Drift is the publisher's crystal
against the origin's. Two independent clocks at a typical +/-50ppm tolerance can separate
by several seconds a day each, and whichever encoder is on the con floor decides the
figure. A con-long session never reconnects, so nothing re-anchors it.

So the design stops depending on the answer. Every index entry carries
`#EXT-X-ARCHIVE-OBSERVED`, the uploader's own wall clock at the moment it first saw that
segment complete:

- `pdt` — the publisher's timeline. What an operator recognises, and what a cut is
  expressed in.
- `observed` — the origin's wall clock. Independent of the publisher entirely.

Drift is then the slope of `observed - pdt`, computable from the archive itself, after the
fact, per session. If it turns out to be negligible, nothing changes. If it is material,
`ArchivePlaylistService` corrects using a fit over that pair without re-archiving a byte.

The constant offset between the two (a few seconds, since a segment is only indexed once
it is complete and no longer last in the playlist) carries no information. Only the slope
does.

Cost is roughly 45 bytes per entry in a file read once per cut. Cheap insurance against a
number nobody can pin down before the event.

## Decisions

1. **Indexing lives in the uploader**, not a separate process, because the uploader must
   parse the playlists anyway for its completion rule.
2. **The index is an HLS playlist per source per hour**, not JSON. One format instead of
   three, and the stored form is already the form a cut needs.
3. **Ordering is `#EXT-X-ARCHIVE-SEQ`**, assigned by the uploader on first observation.
   Never `pdt`, never `session`, both of which are clock-derived.
4. **Archive all three renditions by default**, via a per-source `archive_renditions`
   setting. ~620 GB for a 5-day stream, and ABR survives into the archive. Drop the
   always-on source to `hd` only (~190 GB) if the storage bill turns out to matter; the
   setting exists so that is a config change, not a redesign.
5. **One bucket, two prefixes** on the existing `dvr` disk. `archive/` is bulk and
   expiring, `recordings/` is small and permanent, and prefix-scoped lifecycle rules
   express that without a second set of credentials to manage.
6. **Retention: raw segments live 30 days past publication of the recording that covers
   them.** After that the archive is repacked with `-c copy` into 10-30s segments, which
   cuts object count 5-15x losslessly. Repacking only ever happens after a cut is locked,
   so re-cutting at full 2s granularity stays possible for a month.

## Open questions

1. ~~**Upload throttling.**~~ Resolved. `MAX_UPLOAD_RATE_MBPS` is now a real token bucket
   over the read side of the upload, defaulting to **200 Mbps** on the origin.

   The cap exists so archive traffic cannot starve origin-to-edge egress, which shares the
   same uplink and is the larger consumer (`sources x ladder x edges`, roughly 370 Mbps at
   8 sources and 4 edges). 200 Mbps is 20% of a 1 Gbps link.

   The binding constraint is the floor, not the ceiling. Sustained upload must exceed
   `sources x 11.5 Mbps` or uploads fall permanently behind and the origin disk fills;
   200 Mbps carries 8 sources at 2x headroom. Raise it with the source count, never lower
   it to save bandwidth.

   Because that failure is silent and surfaces hours later, the uploader watches its own
   backlog and logs an error once it has grown for five consecutive minutes, naming the
   source count, the required rate and the configured cap. Verified in the dev stack: at a
   deliberately low 10 Mbps against ~69 Mbps of ingest, throughput fell from 534 to ~32
   segments a minute, the backlog climbed 525 -> 2635, and the guard fired with the correct
   diagnosis. Restoring the cap drained the backlog to zero with `failed=0`.
2. ~~**PDT drift magnitude.**~~ Resolved by removing the dependency rather than by pinning
   the number down: every index entry carries both `pdt` and `#EXT-X-ARCHIVE-OBSERVED`, so
   drift is measurable from the archive after the fact and correctable without
   re-archiving. See *PDT drift* above.
