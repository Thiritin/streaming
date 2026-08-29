# Importing an offline edit

Some recordings are not cut from something that went out live: a ceremony re-edited in
Resolve, a talk assembled from two cameras, a session someone recorded locally. The archive
still has to hold it as segments, because that is the only thing the site knows how to play
back - a recording is a range of a source's archive selected by wall clock, not a file.

`tools/streaming-archiver` does that end to end from the machine holding the master.

## What happens

1. **Start.** The tool asks the site to open an import. The site allocates the archive
   window the segments will occupy (sequential, one clear hour after whatever the prefix
   already holds, so two people importing on the same afternoon cannot interleave), and
   answers with the ladder to encode against.
2. **Encode.** Locally, with ffmpeg, into the same rungs and the same 2s segments the live
   transcoder produces. Rungs above the master's height are skipped rather than upscaled.
3. **Upload.** Presigned PUT per object, straight to the archive bucket. Every key is
   rebuilt server side from the import, so an API key cannot write anywhere else.
4. **Commit.** The tool reports each segment's duration; the site writes the hour indexes,
   verifies every segment is really in the bucket, creates the recording, builds its
   playlist and queues the thumbnail.

The recording arrives **unpublished**. Someone still watches it and publishes it from
`/manage` > Recordings.

## Running it

`/manage` > Settings > Imports has the binaries, one per platform, linked straight from the
latest release. They need `ffmpeg` on the machine that runs them and nothing else.

First give the installation an import key: `/manage` > Settings > Imports > Generate, then
Save. That row is the only source; there is no environment variable behind it, and an empty
key means the import API refuses everything. Hand the key to whoever is importing and
rotate it here when they are done.

```bash
export ARCHIVER_API=https://stream.example.org
export ARCHIVER_KEY=...        # the import key from /manage > Settings > Imports

streaming-archiver import "Opening Ceremony.mp4" --title "Opening Ceremony"
```

The key travels as `X-Import-Key`. It is not the same as the recording API key, which still
guards the older `/api/recording/shows` and `/api/recording/create` endpoints and is set at
`/manage` > Settings > Playback security (`RECORDING_API_KEY` is its shipped fallback); the
import API does not accept it.

It encodes on whatever hardware the importing machine has - Apple's media engine on a Mac,
an NVIDIA card on Windows - which is around eight times faster than software and leaves the
machine usable: an hour of 1080p50 lands in roughly
half an hour of encoding plus the upload. Measured against a lossless reference the two
encoders land within a point of VMAF at the same rung, so the default is not a quality
compromise; `--encoder x264` is there for when an import is worth several times the wall
clock anyway. `--parallel` tunes concurrent uploads.

The ladder's presets come from the server (`App\Support\ArchiveLadder`), so changing what
importers encode with does not mean reissuing binaries.

## Where imports land

Under the `vod` archive prefix by default, which nothing streams to. Imports therefore do
not need a `Source` row at all: a recording resolves its archive through `archive_prefix`
first and only falls back to the source relation. Pass `--prefix` to use another one.

## When it refuses

- **"N segment(s) of hour ... are not in the bucket"** - the upload did not finish. Nothing
  was written to the archive index and no recording was created; run the import again.
- **"the rungs are not cut at the same instants"** - the master has a variable frame rate.
  Re-export it with a constant one; one index entry describes all three rungs, so they have
  to be cut identically.
- **"is outside this import's window"** - an import that ran past 24 hours of archive time,
  or a stale import id being reused.

Imports expire 48 hours after they are opened.

## Ladder changes

`App\Support\ArchiveLadder` is the only description of what a rendition is. The import API
serves it, so a client binary shipped months ago encodes to today's ladder. The live
transcoder keeps its own copy in `docker/ffmpeg-hls/stream-manager.sh` - it runs in a
container this app does not drive - so a ladder change means editing both.
