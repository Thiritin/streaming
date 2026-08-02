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
| Transcoder | `docker/ffmpeg-hls/stream-manager.sh` | ABR ladder, 2s segments, 60-segment sliding window |
| SRS DVR | `docker/origin-srs/origin.conf` | writes segmented MP4 to `/dvr/recordings` |
| Uploader | `docker/dvr-uploader/uploader.py` | watchdog on `.mp4`/`.flv`, upload, delete |
| Trim | `dvr-extract.sh`, `app/Services/DvrExtractorService.php` | concat demuxer + `-ss`/`-t` copy |
| Convert | `dvr-process.sh` | second ABR encode from the trimmed MP4 |
| Model | `app/Models/Recording.php` | `m3u8_url`, `duration`, `thumbnail_path`, `show_id`, `slug` |
| Disks | `config/filesystems.php` | `s3` (app assets, thumbnails) and `dvr` (recordings bucket) |

### Why the current cuts are rough

- SRS writes each `dvr_duration` chunk with its own timestamp base. `concat` with
  `-c copy` splices those bases, so every chunk boundary is a timestamp discontinuity.
- `-ss` after `-i` with `-c copy` keeps original timestamps and starts at the nearest
  preceding keyframe, which gives a frozen head and A/V skew.
- `dvr_wait_keyframe` aligns video only. AAC priming at each splice accumulates drift.
- The copy -> re-encode -> `filter_complex concat` fallback chain in
  `DvrExtractorService` means recordings are not all processed the same way.
- Everything is encoded twice: the live ladder, then a second ladder in `dvr-process.sh`.

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

### 2. Indexer sidecar

A small process (can live in the uploader container) tails each rendition playlist and
maintains a per-hour index. ffmpeg writes `EXT-X-PROGRAM-DATE-TIME` before *every*
segment, not once as an anchor (verified against real output in the dev stack), so the
indexer reads each segment's PDT directly and never has to accumulate `EXTINF` to derive
it. Segment boundaries are also identical across renditions, so one index entry can
describe all three.

```json
// archive/<source>/20260802/16/index.json
{
  "source": "mainstage",
  "hour": "2026-08-02T16:00:00Z",
  "renditions": ["sd", "hd", "fhd"],
  "segments": [
    {"seq": 28800, "n": 42, "name": "mainstage_%v_1754156625_000042.ts",
     "pdt": "2026-08-02T16:00:00.000Z", "dur": 2.0,
     "session": "1754156625", "discontinuity": false},
    ...
  ]
}
```

`%v` is substituted per rendition, so one entry describes all three. A publisher
reconnect sets `discontinuity: true` on the first segment of the new session, which is
what later gets turned back into `#EXT-X-DISCONTINUITY` in generated playlists.

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

`DvrExtractorService`, `dvr-extract.sh`, `dvr-process.sh`, `ExtractDvrSegments` and the
SRS `dvr` config all retire once this is proven.

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
| Publisher reconnect | New session dir, `discont_start`, indexer records `discontinuity: true`, generated playlists emit `EXT-X-DISCONTINUITY` |
| ffmpeg wedged but alive | Segment freshness watchdog kills and restarts |
| ffmpeg restart | Per-session dirs, so no collision and no wipe of unuploaded segments |
| Uploader crash | sqlite manifest plus prefix re-listing on boot; uploads are idempotent by key |
| Segment counter wrap | `%06d` plus per-session dirs |
| Origin disk full | Watchdog degrades the DVR window, then alerts loudly; never deletes unverified |
| Bad cut | Non-destructive; re-cut from the retained archive |

## Sequencing

1. **Done.** Origin changes in `docker/ffmpeg-hls/stream-manager.sh`: `hls_list_size 1800`,
   `%06d`, scoped `stop_ffmpeg` cleanup, orphan reaper, stall watchdog, and the `$!`
   process-substitution fix. Compose knobs in `docker-compose.dev.yml` (5 min window
   locally) and the origin provisioning blade (60 min). Ships the 60 minute rewind alone.
2. Indexer sidecar writing `index.json`.
3. Uploader rewrite: `.ts` support, playlist-based completion, manifest, verified reaper.
4. `ArchivePlaylistService` plus schema migration; generate VOD playlists for one show
   end to end.
5. Edge: `/archive/` and `/recordings/` locations behind the playback token; gzip m3u8.
6. Manage UI: index, then the trim editor.
7. **Done.** Player: `live:dvr` wired through `StreamPlayer.vue`, stream-type-aware
   `backBufferLength`.
8. Retire `DvrExtractorService`, `dvr-extract.sh`, `dvr-process.sh`, `ExtractDvrSegments`,
   and the SRS `dvr` block.

Keep the SRS MP4 DVR running as a cold backup through the next event. It costs disk and
nothing else, and it is the fallback if the segment archive misses something.

## Open questions

1. **Archive all three renditions, or `hd` only, for the con-long stream?** All three is
   ~620 GB for 5 days and preserves ABR in the archive. `hd` only is ~190 GB but the
   archive plays at one quality. Suggest a per-source `archive_renditions` setting,
   defaulting to all three, set to `hd` for the always-on stream if the bill matters.
2. **Archive retention.** How long do raw segments live after a recording is published?
   Once a cut is final, the archive can be repacked with `-c copy` into longer segments
   (10-30s), which cuts object count by 5-15x losslessly. Worth doing, but only after the
   cut is locked.
3. **Who owns the indexer process?** Simplest is a second thread in the uploader
   container, since both need the same view of the segment directory.
4. **`recordings` bucket vs `archive` prefix.** Both currently land on the `dvr` disk.
   Different lifecycle rules (archive is bulk and short-lived, recordings are small and
   permanent) might justify separate buckets.
