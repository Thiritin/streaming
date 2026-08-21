# streaming-archiver

Imports an edited video into the streaming site's segment archive, from the machine that
holds the master.

The site has no notion of "a video file". A recording is a range of a source's archive
selected by wall clock, so an offline edit has to become archive first: the same ladder,
the same segment naming, the same hour indexes the live pipeline produces. This tool
encodes locally, uploads the segments straight to object storage with presigned URLs, and
asks the site to commit the result as an unpublished cut.

Nothing about the archive is baked in. The ladder, the naming and the window all come from
the site on every run, and the hour indexes are written server side from the durations this
tool reports. A binary that shipped months ago therefore still produces today's archive.

## Get a build

Released binaries are linked from `/manage` > Settings > Imports, one per platform. Every
release builds them from this directory and attaches them under fixed asset names
(`.github/workflows/streaming-archiver.yml`), so the panel's "latest" links keep working without
anyone updating a URL. The names are a contract with `App\Support\ImportCli::PLATFORMS`.

To build it yourself:

```bash
cd tools/streaming-archiver
go build -o streaming-archiver .

# cross compile
GOOS=windows GOARCH=amd64 go build -o streaming-archiver.exe .
GOOS=darwin  GOARCH=arm64 go build -o streaming-archiver-macos-arm64 .
GOOS=linux   GOARCH=amd64 go build -o streaming-archiver-linux-amd64 .
```

`streaming-archiver --version` reports the release it came from, or `dev` for a local build.

Needs `ffmpeg` and `ffprobe` on PATH (macOS `brew install ffmpeg`, Debian
`apt install ffmpeg`). They are not bundled: x264 is GPL, and shipping it turns
distribution into a licensing exercise for no functional gain.

## Use

```bash
export ARCHIVER_API=https://stream.example.org
export ARCHIVER_KEY=...          # the import key, from /manage > Settings > Imports

streaming-archiver import "Opening Ceremony.mp4" --title "Opening Ceremony"
```

It prints the import window, encodes, uploads, and ends with the manage URL of a **draft**
recording. Nothing is published automatically: someone still has to watch it and decide.

## Encoders

By default the tool uses Apple's media engine (`h264_videotoolbox`) when ffmpeg offers it,
and libx264 everywhere else. Both were measured on the same 1080p50 material at the ladder's
6000k top rung, VMAF against a lossless reference, 30s per slice:

| slice | encoder | delivered | VMAF mean | VMAF min |
|---|---|---|---|---|
| static, talking heads | x264 veryfast | 3.3 Mbps | 94.7 | 83.7 |
| static, talking heads | videotoolbox | 4.0 Mbps | 94.5 | 76.1 |
| high motion, stage | x264 veryfast | 5.8 Mbps | 78.0 | 56.8 |
| high motion, stage | videotoolbox | 6.1 Mbps | 79.1 | 53.4 |

Within a point of each other, at roughly eight times the speed and a fraction of the CPU.
The difference is bit spend, not quality: VideoToolbox stays near the target rate on easy
content where x264 drops well under it, so an import comes out somewhat larger.

Getting there needed one thing: **no `-maxrate`/`-bufsize` on the VideoToolbox rungs**. Its
rate control answers a ceiling by spending far less than the target, not by trimming peaks
- the same high-motion slice scored 68.5 at 3.7 Mbps with the ladder's 6500k cap and 75.0
at 4.8 Mbps with a loose 8400k one. The cap was the entire quality gap people attribute to
hardware encoders.

`--preset` only applies to x264; VideoToolbox has no equivalent knob.

Both long waits - the encode and the upload - draw a progress bar with an ETA, from
ffmpeg's own reported speed rather than a guess. Off a terminal (CI, `tee`) the bar becomes
a line every ten seconds instead.

Useful flags: `--preset faster` (all rungs, when you want the encode to finish sooner),
`--parallel` (concurrent uploads, default 8), `--work DIR --keep` (keep the encode around
to inspect), `--prefix` (archive prefix, default the site's own), `--slug`, `--description`,
`--date`.

## Export settings that suit it

Anything ffmpeg reads will work, but the ladder tops out at 6 Mbps, so a very large master
mostly costs encode time:

- H.264 or HEVC, 1920x1080, 20-40 Mbps, constant frame rate, progressive
- Stereo audio, 48 kHz
- 10-bit masters are fine; the tool forces 8-bit output per rung

## Notes

- Uploads are HTTP/1.1 only, deliberately. Go's HTTP/2 transport cannot retry a request
  whose body was already written, and S3 front ends tend to send a graceful-shutdown GOAWAY
  under sustained parallel PUTs; over h2 that loses the object outright.
- The server verifies every segment is in the bucket before it writes an hour index, so a
  half-finished upload fails the commit rather than producing a recording that breaks
  during playback.
- Rungs above the master's own height are skipped rather than upscaled.
