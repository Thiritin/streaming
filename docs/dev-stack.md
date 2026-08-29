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
SRS origin
   |  rtmp
ffmpeg-hls (480p/720p/1080p ladder, aligned GOPs; remuxed by default locally)
   |  shared volume ──> archive-uploader ──> versitygw (S3 API) ──> segment archive
   |                                          + per-hour index playlists
origin nginx :8083 ──> origin caddy :8070
   |
edge nginx :8081 (njs verifies ?t= tokens) ──> edge caddy ──> localhost:8085 ──> browser
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
| 7075 | versitygw | S3 API; override with `DEV_S3_PORT` |

8080 belongs to Yerd's daemon and 8081 to Reverb, so the edge avoids both. 7070 is
AnyDesk's default listener, which is why versitygw is not on it: AnyDesk wins the bind
and `dev-stack.sh up` fails part-way through with `address already in use`. Inside the
compose network the service is still on 7070, so only the host port moved.

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

### Keeping the CPU quiet

By default nothing in the stack encodes video after the first few seconds. Two
switches do the work, and both trade fidelity for headroom:

| Variable | Default | What the default does |
|----------|---------|-----------------------|
| `DEV_PUBLISH_MODE` | `loop` | Encodes one clip per channel once, caches it in the `publisher-clips` volume, then pushes it endlessly with `-c copy`. Restarts are instant. The on-screen clock is frozen at capture time. |
| `DEV_ABR_MODE` | `copy` | Remuxes the incoming stream into all three renditions instead of encoding them. `sd`, `hd` and `fhd` all carry the publisher's picture, so switching quality in the player changes nothing visible. |
| `DEV_PUBLISH_LIMIT` | `0` (all) | Caps how many channels publish. |
| `DEV_PUBLISH_SIZE` | `1280x720` | Publisher resolution. |

`DEV_ABR_MODE`, `DEV_PUBLISH_MODE`, `DEV_PUBLISH_SIZE` and
`DEV_PUBLISH_CLIP_SECONDS` are read by compose, so they work from `.env` or the
command line. `DEV_PUBLISH_LIMIT` is read by `dev-stack.sh` itself and has to
be set on the command line.

So a laptop can hold five channels through the full path at roughly idle cost.
Flip either one back when the thing you are testing depends on it:

```bash
DEV_PUBLISH_MODE=live ./scripts/dev-stack.sh publish   # moving clock, real frames
DEV_ABR_MODE=transcode ./scripts/dev-stack.sh up       # the real 480p/720p/1080p ladder
```

Copy mode cuts segments on the publisher's keyframes, so a publisher with a GOP
longer than `hls_time` (2s) produces long, ragged segments. The bundled
publisher already sends a 2s GOP; OBS needs its keyframe interval set to 2 as
well.

### Playback tokens

The edge is built from `docker/edge-nginx`, so it carries njs and
`hls-auth.js` and verifies `?t=<token>` locally with an HMAC, exactly as
production does. It reads `HLS_VIEWER_SECRET`, `HLS_EMBED_SECRET`,
`HLS_TOKEN_LEEWAY` and `STREAM_SYSTEM_STREAMKEY` from your `.env` - a container is
not a Laravel process, so it never sees the settings table. A deployed installation
edits the same four at `/manage` > Settings > Playback security, and `.env` is the
fallback there; locally, set them here or the edge has nothing to verify against.
Without a viewer secret every tokenised request answers 403:

```bash
openssl rand -hex 32   # HLS_VIEWER_SECRET
openssl rand -hex 32   # HLS_EMBED_SECRET
```

Rejections are logged with a reason (`expired`, `bad_signature`,
`source_mismatch`, ...) on the edge:

```bash
./scripts/dev-stack.sh logs edge-nginx
```

Publisher authentication is a separate thing and is unchanged: SRS still calls
`/api/srs/auth` on publish and the app compares `?secret=` against the source's
stored `stream_key`. Playback tokens cover viewers, not broadcasters.

### Storage

versitygw serves a plain directory over the S3 API, so DVR uploads, recordings
and thumbnail writes all take the same code path they take against real object
storage. Point the app at it in `.env`:

```dotenv
AWS_ACCESS_KEY_ID=devkey
AWS_SECRET_ACCESS_KEY=devsecret123
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=streaming
AWS_ENDPOINT=http://localhost:7075
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
