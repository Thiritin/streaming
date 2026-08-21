# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based streaming system for conventions that manages live video streaming infrastructure. It includes server provisioning, viewer session tracking, real-time chat, and a segment archive that recordings are cut from.

Nothing convention-specific is hardcoded. Names, copy, links, logo, login background and accent colour resolve through `App\Services\BrandingService`, backed by the `branding_settings` table with neutral fallbacks in `config/branding.php`. Never reintroduce a convention name, domain, or logo as a literal or a config default.

Branding has exactly one source: the `branding_settings` table, edited at `/manage` > Settings or via `php artisan branding:set key=value`. Do not add `env()` to `config/branding.php` or `BRANDING_*` vars to `.env` - a saved row always wins, so a second source could only disagree. The accent colour is applied as runtime CSS custom properties (`app.blade.php`, after `@vite`), so changing it needs no rebuild; never move it into a `VITE_` var.

The announcement lives in the same table, edited at `/manage` > Settings > Announcement
with defaults in `config/announcement.php`. Two pieces of text: a banner line, rendered on
the front page only (`StreamController@index` passes it; it is not a shared prop and never
appears on the player, the archive or a display), and the full text behind it at
`/announcement`. Read it through `App\Support\Announcement::current()` for the banner and
`::page()` for the page; both answer `null` when the `announcement` feature is off, the
banner is switched off, or the text is empty. Markdown is sanitised by `App\Support\Markdown`,
and the banner's `id` is a hash of its text, which is what a viewer's dismissal is
remembered against.

Feature switches (chat, emotes, boops, announcement, feedback, screens, telegram) have two layers. The installation's switches live in the same table, edited at `/manage` > Settings > Features with defaults in `config/features.php`; a signed-in viewer can then switch any of those off for themselves at `/settings`, stored in `users.feature_preferences`. A viewer can only subtract, never add, and the installation-only flags listed in `Features::INSTALLATION_ONLY` are not offered to viewers at all. Read them through `App\Support\Features`: `Features::enabledFor($key, $user)` or `Features::forUser($user)` anywhere a request has a viewer, `Features::chat()`/`::emotes()`/`::boops()` only where the installation-wide answer is what you want. Never `config('features.*')` directly - the config value is only the fallback, so a `config()` read ignores both layers. Emotes fold into chat on both layers. The installation's set is cached under one key and dropped by `BrandingSetting` on write.

## Core Architecture

### Tech Stack
- **Backend**: Laravel 12 with PHP 8.2+
- **Frontend**: Vue 3 with Inertia.js 2
- **Admin Panel**: Inertia + Vue at `/manage` (no Filament)
- **Real-time**: Pusher/Soketi for WebSockets
- **Streaming**: SRS (Simple Realtime Server) for RTMP/FLV streaming
- **Queue**: Laravel Horizon with Redis (production); database queue driver locally
- **Database**: MySQL 8.0 (production), PostgreSQL locally
- **Infrastructure**: Hetzner Cloud API for server provisioning

### Key Components

1. **Streaming Infrastructure**
   - SRS servers handle RTMP input and HTTP-FLV output
   - Origin and edge server architecture for scalability
   - Automatic server provisioning via Hetzner Cloud API

2. **Models & Relationships**
   - `User`: Attendees with authentication via OpenID
   - `Server`: Streaming servers (origin/edge types)
   - `SourceUser`: Viewer sessions, one row per viewer per source
   - `Message`: Chat messages with moderation features

3. **Real-time Features**
   - WebSocket broadcasting for chat and system events
   - Stream status updates (offline, provisioning, live)
   - Rate limiting and slow mode for chat

4. **Capacity and scaling**
   - **There is no autoscaler.** Servers are provisioned by hand from `/manage` >
     Servers. Jobs under `app/Jobs/Server/` handle the async lifecycle once a
     provision is requested (VM creation, DNS, readiness, deletion).
   - Viewer-to-edge assignment is least-loaded-first and sticky, not a load
     balancer. It lives on the viewing session: `source_users.server_id`, written by
     `HlsController::placeViewer()` on the playlist request, for guests and signed-in
     viewers alike. There is no assignment on `users` and no job that pre-assigns one -
     an account that is not watching holds no edge. `UpdateServerViewerCountsJob`
     refreshes `servers.viewer_count` every 30s from those same rows.
   - Edges are bandwidth-bound, not CPU-bound. `max_clients` is the capacity gate.
   - Each server's `heartbeat.sh` cron posts a system sample every minute (CPU, load,
     memory, disk, network rates, uptime) to `/api/server/{id}/heartbeat`. It lands in
     `server_metrics`, one row a minute, and is charted on the server page at
     `/manage/servers/{id}`. Viewer counts are never taken from the box - they stay
     derived from `source_users`, which knows about guests too.

5. **The show gate**
   - A channel is watchable only while a show on it is `live`. Plenty of sources ingest
     around the clock without being for anyone to watch - a hall camera up through setup,
     a stage sitting on colour bars - so a feed arriving never opens a channel, and
     neither does holding a credential.
   - Enforced in one place on the playlist path: `HlsController::closedToViewers()`,
     which answers 404 rather than 403 (a player treats 403 as worth retrying) off
     `Source::playable($slug)`. That answer is cached for a few seconds and dropped by
     `ShowObserver` whenever a show is created, deleted or changes status, so going live
     and ending both land on the next playlist poll.
   - Nothing hands out a credential ahead of it either. `StreamController::playbackProps()`
     and `initialHlsUrl` answer only for a live show, `Show::canWatch()` is live and
     nothing else (it used to open five minutes before the scheduled start), and
     `DisplayController` mints an embed token per source only while that source has a
     show on it - a screen lists a dark channel but cannot start it.
   - Two callers are exempt and neither is a viewer: the system streamkey (thumbnailer,
     archive uploader) and an operator preview from `/manage` (`?preview=1`, past
     `access-manage`), which exists precisely to check a feed before the show is put live.

## Development Commands

### Local Development

Not Sail/Docker. PHP, Postgres, and Valkey run natively. Yerd serves the site.

```bash
# Install dependencies
composer install
npm install

# Run migrations and seeders
php artisan migrate --seed

# Start dev servers
npm run dev              # Vite dev server for assets
php artisan queue:work   # Process queued jobs (database driver locally, no Horizon needed)
php artisan reverb:start # WebSocket server for chat/broadcasting
```

Site is served by Yerd at `http://streaming.test` (`APP_URL`); no `artisan serve` needed.

### Local Ports

| Port | Service | Notes |
|------|---------|-------|
| 80 | Yerd | serves `streaming.test`; its daemon (`yerdd`) also holds 8080 |
| 5173 | Vite | `npm run dev`; `detectTls: false` in `vite.config.js` so the plugin does not probe Yerd's valet config for certs |
| 8081 | Reverb | WebSockets; `REVERB_PORT`/`REVERB_SERVER_PORT`, 8080 is unavailable |
| 6379 | Valkey | Redis-compatible, reached via the `phpredis` extension and the `REDIS_*` env vars |
| 8000 | Companion | only while `./scripts/companion.sh up` is running; see docs/admin/companion.md |

Local `CACHE_DRIVER=file` and `QUEUE_CONNECTION=database` by design, so Valkey is optional locally; production uses it for cache, Horizon queues, and Reverb scaling.

### Testing
```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run specific test file
php artisan test tests/Feature/ExampleTest.php
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Queue Management
```bash
# Process jobs (local: database driver)
php artisan queue:work

# Production uses Horizon with Redis (visit /horizon)
php artisan horizon
```

## Environment Configuration

Key environment variables to configure:
- `HETZNER_API_TOKEN`: For server provisioning
- `PUSHER_*`: WebSocket configuration
- `OIDC_*`: OpenID Connect settings
- `STREAM_*`: Streaming server configuration
- `CHAT_*`: Chat moderation settings

## Docker Images (production/Kubernetes)

`docker/` contains Dockerfiles for services deployed to Kubernetes in production. Not used for local development:
- `docker/origin-srs`, `docker/origin-nginx`, `docker/origin-caddy`: Origin streaming stack
- `docker/edge-nginx`, `docker/edge-caddy`: Edge streaming stack
- `docker/archive-uploader`: mirrors HLS segments to S3 and maintains the hour indexes recordings are cut from
- `docker/ffmpeg-hls`: HLS transcoder
- `docker/mysql`: Production MySQL init scripts

Root `Dockerfile` builds the main Laravel app image (built via `.github/workflows/docker.yml`).

## Job Queue Architecture

Critical background jobs for server management:
- `Server\CreateServerJob` / `Server\DeleteServerJob`: Hetzner provision and teardown
- `Server\Provision\*` / `Server\Deprovision\*`: the async lifecycle steps (VM, DNS, readiness)
- `Server\ServerHealthCheckJob`: GETs `/health` on each active edge, every minute
- `PruneServerMetricsJob`: drops `server_metrics` rows past the retention window, daily
- `UpdateServerViewerCountsJob`: recomputes `servers.viewer_count`, every 30s
- `CleanupStaleViewerSessionsJob`: closes sessions idle 3+ minutes, every minute
- `FlushShowBoopsJob`: banks cached boops into `shows.boop_count` every 5s and
  broadcasts whatever the request path has not announced yet. Clicks never write to
  the database on the request; the first boop in a show's one-second broadcast
  window does go out from the request, so a quiet room is instant and a busy one
  stays at one message a second. A viewer's boops are budgeted per show in
  `BoopController` (400 a minute, batches over it trimmed, not refused), which is
  what keeps an auto-clicker down to a hand's pace. See `App\Services\BoopCounter`
- Chat command jobs for moderation

The schedule lives in `app/Console/Kernel.php`. There is no scaling job; see
Capacity and scaling above.

## Admin Interface

The admin panel is the Inertia panel at `/manage`. Filament is gone; `/admin` is a 301 into `/manage`.

`/manage` covers:
- Dashboard: capacity, server health, alerts, live viewers, the next few hours of programme
- Sources, Shows, the Show planner and Stream Control
- Preview (`/manage/sources/preview`): the operator's view of what an encoder is actually
  pushing, before a show exists to open the channel. The player asks for the playlist with
  `?preview=1`, which `HlsController::isPreview()` honours for anyone past `access-manage`:
  it skips the show gate, the `source_users` row and the edge pin, so checking a feed never
  shows up as a viewer. Beside it, `App\Support\SourceProbe` reads the ladder off the edge
  directly - which renditions exist, how many segments and how old the newest one is
- Import: pulls sessions from pretalx into shows; see docs/admin/pretalx-import.md
- Each source page carries its control-surface endpoint (`/api/companion/<stream name>`,
  start/stop/status, one control key for the installation); see docs/admin/companion.md.
  Read the key through `App\Support\ControlKey::current()`, never `config('stream.control_key')`
  directly - the table is the only source, and the config entry is a null placeholder that
  names where the settings registry stores it. Never give it an `env()`. The page and
  Settings > Control surfaces also link to the built Companion module, which every
  GitHub release attaches under a fixed asset name
  (`.github/workflows/companion.yml`); the link is `stream.companion_module_url`,
  which unlike the key is a build location rather than an installation setting
- Display Keys and Screens: the codes unattended displays sign in with, plus the screens
  themselves - what each is playing and where to send it. A screen reports itself on its
  poll and picks up a directed source the same way; see docs/admin/displays.md
- Servers, including the generated install script
- Users, Roles, Emotes and Recordings. An edit that never went out live is imported into
  the archive with `tools/streaming-archiver` rather than uploaded; see docs/admin/archive-import.md.
  It authenticates with the import key from Settings > Imports, read through
  `App\Support\ImportKey::current()` and never `config('stream.import_key')` directly -
  same rule as the control key, and `RECORDING_API_KEY` does not open it
- Telegram: the installation's bot and the chats it posts into. One token in Settings >
  Telegram registers the webhook; each chat then decides what it hears (shows, recordings,
  source alerts, feedback, and which sources those cover) and whether its messages carry
  buttons. A show message starts as Start,
  becomes End with a confirmation step, and is kept in step whoever changed the show;
  a report message carries Resolve and a draft recording carries Publish. Source alerts are
  a log: posted, never edited, and suppressed when the state is one the chat already holds. Groups link with `/link <code>`, direct messages by
  chat id. A forum group's topics each link as a row of their own, so one group can split
  shows and reports across topics. See docs/admin/telegram.md. Administrators only, since an interactive chat can
  take a show on and off air
- Feedback: what viewers sent in from the site - the Feedback button in the top bar
  and "Report" on the player. Each report carries the browser, screen, connection and
  player snapshot the client collected, bounded by `App\Support\Diagnostics`, plus an
  optional Telegram handle for a follow-up. Reading is open to anyone past
  `access-manage`; triaging and deleting needs `stream.manage`
- Settings: one pane per group in `config/settings.php`, each with its own URL and its
  own entry in the menu down the left. Branding, login copy, accent colour, footer links,
  the announcement, the feature switches and the control key

Tables, filters, row/bulk actions and toasts are declared server-side with the
`App\Support\Manage` toolkit (`Table`, `Column`, `Filter`, `Action`, `Status`, `Toast`,
`InlineEdit`) and rendered by the shared components in `resources/js/Components/Manage`.
`Table::inlineEdit()` declares the few fields a row may be changed with from the list
itself; answer null for a record that must not be. The toolbar then offers an "Inline
edit" switch, each control saves on its own to that row's endpoint, and the page's poll
stops while the mode is on. Shows use it for source and the scheduled times
(`ShowController::inlineUpdate`, `PATCH /manage/shows/{show}/inline`). Access runs through
the `access-manage` gate plus a policy per model.

## Important Development Rules

- **NEVER use fetch() or make API calls** unless absolutely necessary. Always use Inertia.js 2 props for passing data from backend to frontend. Data should be passed through page controllers or HandleInertiaRequests middleware for global data.
- Never use -gray- for tailwind colors always use -primary- as main color
- no need t orun build i got a npm run dev running
- Local dev runs natively, not Sail/Docker
- The local dev server is **Yerd** (daemon `yerdd`), not Laravel Herd. They are different tools. Never call it Herd.