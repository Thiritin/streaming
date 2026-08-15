# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based streaming system for conventions that manages live video streaming infrastructure. It includes server provisioning, viewer session tracking, real-time chat, and a segment archive that recordings are cut from.

Nothing convention-specific is hardcoded. Names, copy, links, logo, login background and accent colour resolve through `App\Services\BrandingService`, backed by the `branding_settings` table with neutral fallbacks in `config/branding.php`. Never reintroduce a convention name, domain, or logo as a literal or a config default.

Branding has exactly one source: the `branding_settings` table, edited at `/manage` > Settings or via `php artisan branding:set key=value`. Do not add `env()` to `config/branding.php` or `BRANDING_*` vars to `.env` - a saved row always wins, so a second source could only disagree. The accent colour is applied as runtime CSS custom properties (`app.blade.php`, after `@vite`), so changing it needs no rebuild; never move it into a `VITE_` var.

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
     balancer: `User::assignServerToUser()` for signed-in viewers, the
     `source_users` row for guests. `UpdateServerViewerCountsJob` refreshes
     `servers.viewer_count` every 30s from active sessions.
   - Edges are bandwidth-bound, not CPU-bound. `max_clients` is the capacity gate.

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
- `docker/dvr-uploader`: DVR recording uploader
- `docker/ffmpeg-hls`: HLS transcoder
- `docker/mysql`: Production MySQL init scripts

Root `Dockerfile` builds the main Laravel app image (built via `.github/workflows/docker.yml`).

## Job Queue Architecture

Critical background jobs for server management:
- `Server\CreateServerJob` / `Server\DeleteServerJob`: Hetzner provision and teardown
- `Server\Provision\*` / `Server\Deprovision\*`: the async lifecycle steps (VM, DNS, readiness)
- `Server\ServerHealthCheckJob`: GETs `/health` on each active edge, every minute
- `ServerAssignmentJob`: backfills viewers with no edge assignment, every 15s
- `UpdateServerViewerCountsJob`: recomputes `servers.viewer_count`, every 30s
- `CleanupStaleViewerSessionsJob`: closes sessions idle 3+ minutes, every minute
- Chat command jobs for moderation

The schedule lives in `app/Console/Kernel.php`. There is no scaling job; see
Capacity and scaling above.

## Admin Interface

The admin panel is the Inertia panel at `/manage`. Filament is gone; `/admin` is a 301 into `/manage`.

`/manage` covers:
- Dashboard: capacity, server health, alerts, live viewers, the next few hours of programme
- Sources, Shows, the Show planner and Stream Control
- Import: pulls sessions from pretalx into shows; see docs/admin/pretalx-import.md
- Servers, including the generated install script
- Users, Roles, Emotes and Recordings
- Settings: branding, login copy, accent colour and footer links

Tables, filters, row/bulk actions and toasts are declared server-side with the
`App\Support\Manage` toolkit (`Table`, `Column`, `Filter`, `Action`, `Status`, `Toast`) and
rendered by the shared components in `resources/js/Components/Manage`. Access runs through
the `access-manage` gate plus a policy per model.

## Important Development Rules

- **NEVER use fetch() or make API calls** unless absolutely necessary. Always use Inertia.js 2 props for passing data from backend to frontend. Data should be passed through page controllers or HandleInertiaRequests middleware for global data.
- Never use -gray- for tailwind colors always use -primary- as main color
- no need t orun build i got a npm run dev running
- Local dev runs natively, not Sail/Docker
- The local dev server is **Yerd** (daemon `yerdd`), not Laravel Herd. They are different tools. Never call it Herd.