# DVR archive: end-to-end test record

What was tested, what broke, and why each fix is the right one. Companion to
`docs/dvr-archive-plan.md`, which describes the design this validates.

## What was under test

The whole path, from a publisher pushing RTMP to a viewer playing a trimmed recording:

```
publisher -> SRS -> ffmpeg ABR ladder -> HLS segments on origin disk
                                      -> archive_uploader.py -> S3 (segments + hour index)
show markers -> ArchivePlaylistService -> VOD playlist (signed segment URLs) -> player
```

The claim being checked is the one the whole redesign rests on: **cutting a recording is
playlist authoring, not media processing.** No decode, no concat, no `-ss`, no re-encode,
and therefore none of the seams the old MP4 pipeline produced.

## Environment

Local dev stack (`./scripts/dev-stack.sh up`), six publishers looping a clip into SRS,
`ABR_MODE=copy`. The stream ran continuously for about two hours, which also exercises the
case that motivated the redesign: a source that never stops, so nothing can wait for it to
end.

Dev differs from production in two ways that matter when reading these numbers:

- `ABR_MODE=copy` means all three renditions carry the same bitstream, so their file sizes
  are identical. Production transcodes a real 1500/3500/6000 kbps ladder.
- The publisher loops a 20s clip with `-stream_loop -1 -fflags +genpts`, which regenerates
  timestamps at every loop boundary. This matters for drift (below).

## Results

### Archive capture

```
indexed=768 uploaded=2304 verified=2304 reaped=0 failed=0
```

768 logical segments, 2304 uploads, exactly the 1:3 ratio expected from one index entry
describing three renditions. Zero rendition-misalignment warnings, so the assumption that
FFmpeg cuts all renditions at identical instants held throughout.

Renditions archived: `sd 2849`, `hd 2852`, `fhd 2852`. Multi-quality survives into the
archive, which is one of the things the old re-encoding pipeline threw away.

### Disk release

```
local segments   2796   (6 sources x 3 renditions x 150-segment window, plus in flight)
oldest local     354s   (against a 300s dev window)
S3 segments      26013  and climbing
```

Local disk stays bounded by the rewind window while S3 keeps everything. Deletion only
happens after S3 has confirmed the object, so the "check S3, then delete" requirement holds
literally.

### Upload throttle and its failure mode

Deliberately misconfigured to 10 Mbps against ~69 Mbps of ingest:

```
throughput   534/min -> ~32/min
backlog      525 -> 1062 -> 1598 -> 2078 -> 2635

ERROR Upload backlog has grown for 5 minutes straight (525 -> 1062 -> 1598 -> 2078 -> 2635).
      The archive is not keeping up with ingest and the origin disk will fill.
      6 source(s) need ~69 Mbps sustained; cap is 10.0 Mbps.
```

Restoring the cap drained the backlog to zero with `failed=0`, which also exercises crash
recovery: every segment queued during the throttled period was picked up from the manifest.

### Cutting

Show aired 14 minutes on a continuously running source, then cut and re-cut:

```
draft cut    419 segments   839s (13:59)    expected ~840s
re-cut       300 segments   601s (10:01)    delta -238s (trimmed 2 min from each end)
playlists    3 renditions, VOD + ENDLIST, every segment URL signed
size         135 KB raw / 15 KB gzipped
```

Playback of the trimmed cut, fetched over presigned HTTP:

```
duration 600.640989
frame=18000          exactly 300 segments x 2s x 30fps
hard errors: none
```

Frame-exact. Nothing dropped, nothing duplicated, no seams.

## Issues found and fixed

### 1. Encoders leaked forever (pre-existing, production impact)

`stream-manager.sh` launched FFmpeg as `ffmpeg ... 2>&1 | sed ... &` and stored `$!`. For a
backgrounded pipeline `$!` is the PID of the **last** stage, so every stored PID was sed's.
`stop_ffmpeg` killed the log prefixer and left FFmpeg encoding indefinitely.

Demonstrated directly: killing the stored PID left the producer alive.

Only the `pgrep -f` fallback in `start_ffmpeg` ever cleaned one up, and only if the same
stream came back; a stream that ended for good leaked its encoder permanently.

**Fix:** process substitution, `> >(sed ...) 2>&1 &`, so `$!` is FFmpeg's own PID and the
sed exits when the pipe closes. Verified: repeated watchdog restarts leave zero strays.

### 2. `stop_ffmpeg` deleted the archive (pre-existing)

`rm -f "$OUTPUT_BASE_DIR/${stream}"*` — the glob sits outside the quotes, so it expanded and
took every segment with it. Harmless when segments were disposable; fatal once they are the
archive.

**Fix:** restricted to playlists. Segments are left for the reaper, which only deletes what
S3 has confirmed.

Worth noting the sibling: `start_ffmpeg` had `rm -f "$output_dir/${stream}_*.ts"` with the
glob **inside** the quotes, so it never expanded and had always been inert. Removed rather
than "fixed", since fixing it would have introduced the bug it appeared to have.

### 3. Session id collisions (pre-existing, silent data loss)

`timestamp_prefix=$(date +%s)` has one-second resolution. Two FFmpeg starts inside the same
second produce the same prefix, and the second session then writes
`${stream}_hd_<same>_000000.ts` directly over the first session's segments. A fast
crash-restart loop through `check_streams` (5s interval) reaches this.

The failure is silent archive corruption rather than an error, and the segment filename is
the archive's primary key, so a collision corrupts S3 objects and index entries alike.

**Fix:** bump the prefix while any segment already carries it. Works because the orphan
reaper leaves previous sessions' segments on disk.

### 4. PDT drift: measured, then designed around

Measured 245 samples over 41 minutes: slope -21.3 s/day (95% CI -35.0 to -7.6), which
extrapolates to -106s over a five day event.

**That number is not trustworthy**, and chasing a better one would have been wasted effort.
The dev publisher crosses ~123 `-stream_loop` boundaries in that window, each regenerating
timestamps; a few milliseconds lost per boundary accounts for the entire observed shift. It
measures the publisher, not the muxer.

More importantly, no dev measurement can answer the real question. Drift is the publisher's
crystal against the origin's, and whichever encoder is on the floor decides it.

**Fix:** stop depending on the answer. Every index entry now carries
`#EXT-X-ARCHIVE-OBSERVED` (the origin's own wall clock at first observation) alongside `pdt`
(the publisher's timeline). Drift becomes the slope of `observed - pdt`, computable from the
archive itself, after the fact, per session, and correctable without re-archiving a byte.
Cost is ~45 bytes per entry in a file read once per cut.

### 5. Ordering must not touch a clock

The first design ordered segments by `session` + `n`. `n` is FFmpeg's own counter and is
clock-free, but `session` is `date +%s` and therefore is not, so ordering across session
boundaries still depended on the clock.

**Fix:** `#EXT-X-ARCHIVE-SEQ`, assigned by the uploader on first observation. Observation
order follows playlist order, which is inherently monotonic. A clock jump now costs cut
*accuracy* and can never corrupt archive *order*.

### 6. Cut markers silently shifted by the timezone offset (introduced here)

The most subtle one, and the reason the end-to-end test was worth running.

The migration made `recordings.starts_at/ends_at` `timestamptz`, which looks like the more
careful choice. Laravel serialises datetimes for Postgres as `Y-m-d H:i:s` with **no
offset**, so a Carbon in the app timezone (Europe/Berlin) arrives as bare wall-clock digits
and Postgres reads them in the session timezone (UTC). The instant moves by the offset on
write.

It hid well: in memory the value is correct, so a cut built immediately after saving worked
and reported the right duration. Only a later re-read showed the shift, as a recording that
had built fine resolving to zero segments — surfacing as "no archived segments cover that
range", which reads like expiry or an upload lag rather than a timezone bug.

**Fix:** `timestamp without time zone`, matching `shows.actual_start` and `recordings.date`
and the rest of the schema. Comparisons stay correct because `ArchivePlaylistService`
normalises both ends to UTC before comparing against segment timestamps, which is the only
place the distinction actually matters. The existing rows are converted with
`USING starts_at AT TIME ZONE 'UTC'`, preserving the instant Postgres currently holds.

Related guard added in the service: markers are explicitly `->utc()`-normalised before use,
so a naive local string arriving from a `datetime-local` input cannot shift into an hour
bucket that holds no segments.

### 7. Empty segment range raised a bare `ValueError`

`renderMediaPlaylist` called `max()` on an empty array when a cut resolved to nothing. Reachable
from the request path, not just from `build()`: a recording whose hours have expired out of the
archive resolves to zero segments.

**Fix:** an explicit exception naming the likely cause, which the playlist controller turns
into `410 Gone`.

## A false alarm worth recording

The first service-generated cut produced 399 `non monotonically increasing dts` warnings —
exactly the class of defect the whole project exists to eliminate. It was not one.

Ruled out in turn: tag order (identical count when reordered), `PROGRAM-DATE-TIME` presence
(identical with it stripped), the archive copies (40 archive segments decode with zero
warnings), and session boundaries (the range was a single contiguous session with no
discontinuities).

The warnings come from the **null muxer**, which enforces monotonic DTS while remuxing —
something no player does. The decisive measurement was the frame count: 26,940 frames for
449 segments, exactly `449 x 2s x 30fps`. Content is bit-intact.

Recorded because an earlier 31-segment test showed zero warnings and nearly let this be
filed as "fine" without checking, and because `-f null` is a misleading way to test HLS
correctness.

## Still not covered

- **Production ABR.** Everything above ran with `ABR_MODE=copy`. A real transcode ladder
  should be exercised before the event.
- **Archive expiry.** Retention is designed (never expire a segment a published recording
  references) but not implemented or tested.
- **Reaper under S3 outage.** The code refuses to delete unverified segments; the path has
  not been exercised with S3 actually unavailable.
- **The manage UI.** The cut editor and the create-from-show action are wired but have only
  been exercised through the service and controller layers, not clicked through in a browser.
- **Long-run session behaviour.** The longest continuous run so far is about two hours, not
  the five to seven days the main source will actually see.
