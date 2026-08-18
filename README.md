# Streaming

A self-hosted livestream platform for conventions. It takes RTMP from the encoders in your rooms, transcodes an adaptive ladder, delivers HLS through edge servers you can add and remove during the event, and gives the video team one panel for the whole thing: programme, chat, moderation, recordings and infrastructure.

Nothing about any one convention is baked in. Names, copy, links, logo, login background and accent colour live in the database and are edited in the admin panel.

![Browse](.github/screenshots/browse.jpg)

## What it does

**Watch.** A browse grid with a live hero, per-channel filters, hover previews and a programme guide. The player is HLS with a quality ladder, a seekable live window, theatre mode, a pop-out chat and an external-player link for VLC and friends. It works on a phone.

<img src=".github/screenshots/mobile-player.jpg" alt="The player on a phone" width="320">


**Chat.** One channel per source, so chat survives the handover from one show to the next. Timeouts, bans, purge, clear, announcements, slow mode and per-message actions, plus chat commands for moderators. Custom emotes are uploaded by users and approved by staff.

**Programme.** Shows are planned on a drag-and-drop timeline, imported from [pretalx](docs/admin/pretalx-import.md), or created by hand. [Auto mode](docs/admin/auto-mode.md) starts a show when its source comes online and stops it at a hard stop, so nobody has to sit on the button at 2am.

**Archive.** Every source is recorded continuously to object storage. A recording is a time range over that archive, cut in a timeline editor with in and out markers, and rebuilt from the current markers whenever you move them. Published recordings are grouped by year and can require a role to watch.

**Infrastructure.** Edge servers are provisioned on Hetzner Cloud from the panel, get their DNS record, run a generated install script, and are handed viewers by the assignment job. Health checks, viewer counts, capacity and alerts sit on one dashboard.

**Access.** Sign-in goes through OpenID Connect. Roles carry permissions, and shows and recordings can require one. With `AUTH_REQUIRED=false` the public pages are open to guests and only chat stays behind sign-in.

## Screenshots

| | |
|---|---|
| ![Player and chat](.github/screenshots/player-chat.jpg) | ![Programme guide](.github/screenshots/schedule.png) |
| Player, live chat and moderator badges | Programme guide across channels and days |
| ![Archive](.github/screenshots/archive.jpg) | ![Cut editor](.github/screenshots/manage-cut-editor.jpg) |
| Archive, one collection per year | Cutting a recording out of the continuous archive |
| ![Dashboard](.github/screenshots/manage-dashboard.png) | ![Planner](.github/screenshots/manage-planner.png) |
| Capacity, server health and what is on air | Planning the programme on a timeline |
| ![Shows](.github/screenshots/manage-shows.png) | ![Settings](.github/screenshots/manage-settings.png) |
| Shows, with stream control per row | Branding, colours and links, applied without a rebuild |
| ![Sources](.github/screenshots/manage-sources.png) | ![Sign-in](.github/screenshots/login.png) |
| Sources, one per room, each with its own stream key | Sign-in, with what is on air next to it |

The demo content in these screenshots is [Big Buck Bunny](https://peach.blender.org/) (CC BY 3.0, Blender Foundation).

## How it works

```
OBS / encoder
   |  RTMP, one stream key per source
SRS ingress ──DVR──> uploader ──> S3 ──> archive playlists ──> recordings
   |
ffmpeg ABR ladder (480p / 720p / 1080p, aligned GOPs)
   |
origin (nginx + caddy)
   |
edge servers (njs verifies playback tokens)
   |
viewers
```

The Laravel app never carries video. It hands out signed playback tokens, assigns viewers to an edge, and proxies playlists so a viewer only ever talks to its own domain. Segments come from the edges. Archive segments come from the bucket as presigned URLs, which is why a recording playlist is rendered per request rather than stored.

## Stack

Laravel 12 on PHP 8.2, Inertia 2 with Vue 3 and Tailwind 4, Vidstack and hls.js in the player, Reverb or Pusher for websockets, Horizon on Redis for queues, MySQL or PostgreSQL, S3-compatible object storage, SRS and ffmpeg for the video path, Hetzner Cloud for servers.

## Running it locally

You need PHP 8.2+, Composer, Node 20+, a database, Redis or Valkey, ffmpeg, and Docker if you want the full video path.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan queue:work
php artisan reverb:start
```

Video is optional for UI work, and comes in two flavours:

```bash
# fake channels written straight to disk, no Docker
php artisan db:seed --class=DevStreamChannelsSeeder
./scripts/dev-streams.sh          # then set DEV_STREAMS=true in .env

# the real path: SRS, ABR ladder, origin, edge, S3, DVR
./scripts/dev-stack.sh up
```

[docs/dev-stack.md](docs/dev-stack.md) covers both, including the switches that keep five channels running on a laptop without melting it.

## Configuration

The settings that matter live in `.env`:

| Variable | What it controls |
|---|---|
| `OIDC_URL`, `OIDC_CLIENT_ID`, `OIDC_SECRET` | Identity provider |
| `AUTH_REQUIRED` | Whether guests can watch |
| `CHAT_ENABLED`, `CHAT_*` | Chat rate limits, slow mode, link handling. `CHAT_ENABLED` is only the initial default now; chat, emotes and boops are switched from /manage > Settings > Features, and each viewer can switch them off again for themselves at /settings |
| `HLS_VIEWER_SECRET`, `HLS_EMBED_SECRET` | Playback tokens the edges verify |
| `AWS_*`, `DVR_AWS_*` | Archive and DVR buckets |
| `HETZNER_TOKEN`, `DNS_*` | Server provisioning and DNS records |
| `STREAM_SYSTEM_STREAMKEY` | Shared secret between the app and the video stack |

Branding is not an env var. Convention name, copy, footer links, logo, login background and accent colour are stored in the `branding_settings` table and edited at `/manage` > Settings, or scripted:

```bash
php artisan branding:set convention_name="Example Con" primary_color="#7c5cff"
```

The accent colour is applied as CSS custom properties at runtime, so changing it takes effect without a rebuild.

## Deployment

The root `Dockerfile` builds the app image. `docker/` holds the images for the video path: origin SRS, origin and edge nginx and caddy, the ABR transcoder, and the DVR uploader. Queues run under Horizon, websockets under Reverb, and the scheduler needs `php artisan schedule:run` every minute.

## Documentation

- [docs/dev-stack.md](docs/dev-stack.md): the local video stack
- [docs/admin/pretalx-import.md](docs/admin/pretalx-import.md): importing the programme
- [docs/admin/auto-mode.md](docs/admin/auto-mode.md): starting and stopping shows without a human
- [docs/dvr-archive-plan.md](docs/dvr-archive-plan.md): how the archive and cutting work

## Contributing

Issues and pull requests are welcome. For anything larger, or for help running this at your own convention, contact @Thiritin on Telegram.

## Licence

GPL-3.0. See [LICENSE](LICENSE).
