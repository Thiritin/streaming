# Local streaming stack

Two ways to get video on a laptop. Pick by what you are working on.

| | What it runs | Use it for |
|---|---|---|
| **File loops** (`scripts/dev-streams.sh`) | ffmpeg writing HLS into `public/dev-streams/` | UI work. No Docker, starts in a second. |
| **Full stack** (`scripts/dev-stack.sh`) | SRS ingress, ABR transcoding, origin, edge, S3 | Deployment behaviour, DVR, thumbnails, load tests. |

The Laravel app always stays native under Yerd. Containers reach it through
`host.docker.internal`.

## File loops

```bash
php artisan db:seed --class=DevStreamChannelsSeeder
./scripts/dev-streams.sh          # Ctrl+C stops every channel
```

Set `DEV_STREAMS=true` in `.env`. `Source::getHlsUrl()` then returns
`/dev-streams/<slug>/index.m3u8` instead of the edge proxy route, so the browse
hero and tile hover previews play real video with nothing else running.

Four channels, each a different pattern so they are easy to tell apart:
`prime`, `dance-stage`, `panel-room`, `art-track`.

## Full stack

```bash
php artisan db:seed --class=DevStreamChannelsSeeder
./scripts/dev-stack.sh up
```

Leave `DEV_STREAMS` false here: the app should go through its own `/hls` routes,
which proxy to the edge server row on `localhost:8085`, exactly as in production.

```
publisher (ffmpeg, stands in for OBS)
   |  rtmp://localhost:1935/ingress/<slug>?secret=<stream_key>
SRS origin  ──DVR mp4──> dvr-uploader ──> versitygw (S3 API) ──> recordings, thumbnails
   |  rtmp
ffmpeg-hls (480p/720p/1080p ladder, aligned GOPs)
   |  shared volume
origin nginx :8083 ──> origin caddy :8070
   |
edge nginx :8081 ──> edge caddy ──> localhost:8085 ──> browser
```

The container configs in `docker/dev/` are generated copies of the production
ones in `docker/`, with upstreams pointed at compose service names. Regenerate
them if the production configs change.

### Ports

| Port | Service | Note |
|------|---------|------|
| 1935 | SRS RTMP ingress | point OBS here |
| 1985 | SRS HTTP API | `curl localhost:1985/api/v1/streams` |
| 8070 | origin caddy | debugging |
| 8085 | edge caddy | matches the seeded edge server row |
| 7070 | versitygw | S3 API |

8080 belongs to Yerd's daemon and 8081 to Reverb, so the edge avoids both.

### Commands

```bash
./scripts/dev-stack.sh up        # start everything and begin publishing
./scripts/dev-stack.sh publish   # restart broadcasters with fresh stream keys
./scripts/dev-stack.sh status    # what SRS thinks is live, plus container state
./scripts/dev-stack.sh logs edge-nginx
./scripts/dev-stack.sh down      # stop, keep volumes
./scripts/dev-stack.sh reset     # stop and wipe HLS, DVR and S3 volumes
```

Stream keys are encrypted in the database, so the publisher containers get them
from `php artisan dev:stream-keys`, which `dev-stack.sh` calls for you.

### Storage

versitygw serves a plain directory over the S3 API, so DVR uploads, recordings
and thumbnail writes all take the same code path they take against real object
storage. Point the app at it in `.env`:

```dotenv
AWS_ACCESS_KEY_ID=devkey
AWS_SECRET_ACCESS_KEY=devsecret123
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=ef-streaming
AWS_ENDPOINT=http://localhost:7070
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Swap the `s3` service for MinIO if you want a browser console; nothing else in
the stack cares which one is behind the endpoint.

## Load testing

```bash
./scripts/load-test.sh                 # 50 viewers, 60s, first channel
./scripts/load-test.sh 200 120 prime   # 200 viewers, 120s, channel "prime"
```

Each viewer is an ffmpeg client pulling the real ladder through the edge, so
playlist refreshes, segment fetches and nginx caching all count. Every viewer
shares one source IP, and the edge rate-limits per IP (30 r/s, see
`docker/edge-nginx/nginx.conf`), so past a few hundred viewers you are measuring
the rate limiter rather than the server. The summary prints failure counts and
the most common ffmpeg errors, then the edge's response-code spread.
