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

6. **The event calendar**
   - An event is one run of the convention: a name and the days it covers, in
     `events` (`starts_on`, `ends_on`, both `date`). The window is inclusive of the
     closing day - `Event::endsAt()` is its end of day - because a run billed as
     going "to Sunday" is not over on Sunday morning. Managed at `/manage` >
     Settings > Events, another settings area whose contents are rows, joined to the
     menu by hand in `Settings::navigation()` alongside Categories.
   - It gates nothing. Two things read it and nothing else may: whether the site is
     in its live state, and what a show or a recording is filed under. A window
     being shut never stops a stream and never hides one - see the front page below.
   - Read it through `Event::current()`, `::next()`, `::latestFinished()`,
     `::isLive()` and `::configured()`, which come out of one cached table read
     (`Event::forgetWindow()` drops it, and every write does). `Event::forDate()` is
     what `Show::creating` and `Recording::creating` file a new row by. Auto-assign
     is on create only: an edit that clears the event means it was cleared on
     purpose, so guessing it back on every save would make the field impossible to
     empty. `/manage` > Settings > Events > an event > "File them under this event"
     is the backfill for an archive that predates the calendar, and it only touches
     rows filed nowhere, so an overlapping run cannot steal another's programme.
   - A show carries the event; its recordings inherit it, with `recordings.event_id`
     as the override for an edit imported without a show. Read it through
     `Recording::effectiveEvent()` and filter with `inEvent` / `notInEvent`, never
     `where('event_id', ...)` - that misses everything that has its event through
     its show. Both tables offer a Set Event bulk action.
   - Every list read one run at a time - Shows, Recordings and the recording plan -
     is narrowed by event and never by calendar year, and opens on the latest run:
     `Event::mostRecent()`, which is the one that is on, else the one that just
     finished, else the one that is next. A run already in the calendar has no
     programme yet, so newest-by-date is the wrong answer. The options come from
     `App\Support\EventFilter`: every run, plus `all` to switch the filter off and
     `none` for the rows filed under no run at all, which is the only way to find a
     programme imported before the calendar existed. The rail's outstanding badge is
     scoped to the same run. With no calendar the default is `all`, so nothing
     changes shape.
   - A filter with a default is cleared by sending it back empty, not by dropping it
     from the URL: `Table::resolveFilterValues()` reads presence rather than value,
     since ConvertEmptyStringsToNull would otherwise make an emptied filter
     indistinguishable from an absent one and the default would come straight back.
   - `Event::configured()` gates every behaviour change, so an installation that has
     never set the calendar up keeps exactly the shape it had before events existed.

7. **The front page's two modes**
   - `StreamController@index` answers `archiveMode`. Inside a window the page is a
     programme: hero, what is on, what is next. Between runs there is nothing to be
     next, so it becomes the archive - a header saying which run ended and when the
     next one opens, Most watched, then the grid.
   - Anything on air wins, always. `archiveMode` is false whenever a show is live or
     starting soon, on the server and again in `ShowsGrid.vue` (Echo can put a show
     on air without a page load). A stream outside a window - a test, a one-off -
     must never be buried because the calendar says the convention is over.
   - The archive slice below the grid is split by run, not by the six-month cutoff it
     replaces: the run that is on, or the last one to finish, leads it and everything
     else goes in the section underneath. The cutoff survives only as the fallback
     for an installation with no calendar.

8. **The archive page**
   - `/archive` is one grid, newest first until asked otherwise, paged in through
     `Inertia::merge` and `WhenVisible` as it is scrolled. Chips (events, then the
     categories inside the selected one) and a sort narrow it in place. There are no
     source chips: which room a recording came out of is not how anyone looks for it.
     The category chips are counted against the run that is selected, so a chip never
     says four and hands back one. The archive runs to around
     twenty recordings a year, so a wall of shelves would show the same recordings
     three times over; the one shelf left is Continue watching, on the unfiltered
     page only. `/archive/year/{year}` is now a redirect into `?year=`, kept for
     links already handed out.
   - The collection chips are events, not years: a run is what people mean when they
     say which year a recording is from. A recording filed under no run keeps a year
     chip after the events, which is the only place a year chip survives and it
     disappears once everything is filed. `?event=` and `?year=` are both honoured;
     the two are one axis, so picking either clears the other.
   - Categories are what a show is - a dance, a theatre piece, a musical performance -
     and they gate nothing. The set is managed at `/manage` > Settings > Categories
     (a settings area whose contents are rows, so it joins the settings menu by hand in
     `Settings::navigation()` and renders the same shell through `SettingsNav`); a show
     carries one and its recordings inherit it, with `recordings.category_id` as the
     override for an edit imported without a show. Read it through
     `Recording::effectiveCategory()` and filter with the `inCategory` scope, never
     `where('category_id', ...)` - that misses everything that has its category
     through its show. Both tables offer a Set Category bulk action, which is what
     makes an existing archive categorisable.
   - A tile hovers against the recording's own playlist - muted, lowest rendition, a
     little way in - because there is one still per recording and no storyboard sprite.
     `useHoverPreview` allows exactly one preview on the page at a time and refuses on
     touch, reduced motion and save-data. Never let a grid open more than one. Once a
     preview is up the tile scrubs: the cursor's x position picks one of six chunks
     and the bar under it shows them. Rows in the watch page's rail preview the same
     way, through the same composable and so under the same one-at-a-time rule. Chunks and not a free seek, because every
     distinct position is a segment fetched off the edge.
   - Skip points are what a viewer is offered a way past - an intermission, a wait
     before the doors. Ranges in `recordings.skip_segments`, seconds from the start,
     read through `Recording::skips()` and normalised by `App\Support\SkipSegments`
     (sorted, merged, clamped to the duration), never off the column. They gate and
     cut nothing: the player shows a button while the playhead is inside one and only
     a press moves it, so somebody who wants to watch the intermission still can.
     They move with the cut: trimming the head shifts every one of them by the
     same seconds (`SkipSegments::shift()`), because a skip is seconds from the
     start and an in-point nudged forward would otherwise leave every button
     minutes from the intermission it belongs to. A save also carries the cut it
     was marked against (`Recording::cutFingerprint()`, sent as `cut_fingerprint`);
     a save built against a cut somebody else has since changed is refused rather
     than written on top, which is the one case a shift cannot fix - two people
     working the same recording, one trimming and one marking.
     Marked in `/manage` > Recordings > the recording's form and nowhere else, with
     `SkipEditor` beside a player of its own: park the playhead, press in, park it
     again, press out (I and O, N for a new one, Del to drop one, , and . to nudge).
     They save with the rest of the form.
   - Comments live under the recording on its watch page, in `recording_comments`,
     and are switched by the `comments` feature flag (installation-wide in
     /manage > Settings > Features, and a viewer can hide them for themselves in
     /settings). The thread is one level: a row with a `parent_id` is a reply, a
     row without one is not, and `RecordingCommentController::store()` re-points a
     reply-to-a-reply at the top of its thread rather than letting depth grow.
     Deleting takes the replies with it, by cascade, because a reply left behind
     answers whatever ends up above it. Signed-in only - a comment is attributed -
     and a chat ban or timeout silences the box too, since it is the same audience.
     Reporting a comment hides it from the room on the spot and asks moderation
     afterwards, because the point of the button is the hour between somebody
     seeing a thing and a moderator being awake: `recording_comments.hidden_at`
     plus a row in `recording_comment_reports` (one per person per comment, with
     the reporter's own sentence, kept after a ruling so an account that reports
     everything is visible). It is never hidden from its author - somebody
     watching their own words vanish starts again in a new thread - nor from a
     moderator, who sees it flagged with what was said and can approve it back
     from the page. Approving sets `approved_at`, after which a further report
     cannot hide it again, which is what stops one account silencing another for
     good. Read a thread through `RecordingComment::visibleTo($user)`, never off
     the table: a plain read leaks the hidden rows. Counts follow the same scope,
     so a hidden comment is not a gap in the total.
     A chat that asked for `notify_comments` is posted the comment and what was
     said about it, and an interactive one can approve it, delete it or ban its
     author from the message - banning asks twice, like ending a show. Every
     decision, wherever it is made, syncs the others through
     `SyncTelegramMessagesJob` with `TelegramMessage::KIND_COMMENT`.
     Its author can edit it (`edited_at`, shown as "(edited)"), which drops the
     approval with it - a comment approved and then rewritten has not been looked
     at - and delete it. Moderators deliberately cannot edit: putting words in
     somebody else's mouth is what deleting exists instead of.
     A silenced viewer - chat ban or timeout - is shown no section at all rather
     than a read-only one (`RecordingCommentController::availableTo()`), and one
     viewer's posts are spaced by `COOLDOWN_SECONDS` with `HOURLY_LIMIT` an hour
     on top of the route throttle.
     Hearts are `recording_comment_hearts`, one per person per comment, and they
     order the thread: most hearted first, newest first between the ones nobody has
     hearted. Roots are paged twenty at a time and Load more widens the window
     (`?comments=`) rather than fetching a page of its own, so posting and hearting
     re-render everything the viewer had open. Read the thread through
     `RecordingCommentController::thread()`; nothing here uses fetch(). An author
     deletes their own and a moderator deletes any, from the page itself or from
     /manage > Comments, which is the sweep for a run of spam.
   - `recordings.views` counts viewers, not renders. The watch page is an Inertia
     visit, so a reload, a comment posted or a heart pressed renders it again, and
     counting each one put every viewer of a popular recording behind the same row
     lock - enough of them to hold up every Octane worker in the pool. One viewer
     counts once per thirty minutes: `App\Support\RecordingViews` claims a cache key
     (the account, or the session for a guest) and only the claim that lands
     increments. The write goes through the query builder, because a view is not an
     edit - it must not touch `updated_at` and must not wake `RecordingObserver`.
   - Autoplay never plays the same recording twice in a row of its own accord. The
     rail is the rest of the same source, newest first, so two recordings on one
     source point at each other and a tab left alone rolled A, B, A, B until it was
     closed. `RecordingPlayer.vue` keeps the chain of what autoplay has already
     rolled through in session storage and takes the first rail entry that is not in
     it; with nothing left the countdown does not start. Arriving at a recording that
     is not in the chain, or pressing the card, means the viewer chose it, which
     starts the chain again.
   - Playback position lives in `recording_progress`, one row per viewer per recording,
     written by the player every 15s and on the way out. Signed-in only: there is
     nothing to key a guest's row on, and Continue watching is assembled server-side
     with the rest of the shelves. The row's `duration` is the length the player
     measured and `fraction()` reads it before `recordings.duration`: the record's
     number goes stale, and measuring a tile's bar against a different length than
     the player's bar is what made the two disagree. The player also remembers its
     last report in session storage (`useRecentProgress`), because a grid restored
     from Inertia's history cache redraws from props fetched before the visit; the
     server's row wins as soon as it is the newer of the two.
   - The grid arrives as a merge prop so scrolling can append pages to it. A filter is
     not another page: every filter visit passes `reset: ['recordings']`, or the run
     just switched away from stays in the grid under the one that was asked for.
   - Search suggestions (`/archive/suggest`) are the one place the front end talks to
     the server outside Inertia, because they answer per keystroke.
   - The player never lets vidstack remember a playhead (`PlaybackAgnosticStorage` in
     `VideoPlayer.vue`); volume, mute and quality only. Vidstack seeks to its stored
     time on every can-play, not once per source, so a stall or a level switch put the
     playhead back where it was a moment before and the same seconds played round and
     round. Resuming is ours, from `recording_progress`, applied once per `src` - which
     is also why the guard resets on a source change: one recording rolling into the
     next is an Inertia visit to the same component, so nothing unmounts and nothing
     resets by itself. Anything on `RecordingPlayer.vue` that describes the recording
     being watched has to be put back by hand for the same reason.

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
- Sources, Shows and Stream Control. The planner is a mode of the Shows list rather
  than a section: "Open planner" on that page opens `/manage/shows/planner`, which
  renders without the rail - one day, sources as columns, hours down the side, blocks
  dragged to move and the bottom edge to resize - and closes back to Shows. It opens on
  the hours that day's programme actually occupies, with a switch for all twenty-four
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
- Recording Plan (`/manage/recordings/plan`): the grid the programme is divided up on and
  accounted for, worked as a board by several people. Three axes on `shows`, deliberately
  kept apart because they fail independently: `publish_plan` (undecided/yes/no); the two
  captures, `stream_condition` (what the uploader mirrored) and `onsite_status` (the
  room's copy, a fallback that is only chased when the stream one failed); and the archive
  FTP deposit, `archive_pgm_at` / `archive_iso_at`. Plus `recording_owner_id` and
  `recording_note`. None of them gate anything - the uploader still mirrors everything and
  `is_published` still decides what a viewer sees. The Recording column is derived by
  `Show::recordingState()`, so it cannot go stale, and nothing is written off until both
  captures are gone: an outstanding list that never shrinks stops being read. Cells save
  one at a time and concurrently, so a cell holds its draft until the rows read the saved
  value back rather than dropping it when its own reply lands - several replies are in
  flight and each carries a whole page of rows, so the last one to arrive is not the
  newest. Hide done drops only what is finished on both axes (published or `no`, and the
  PGM deposit made); a write-off stays. See docs/admin/recording-plan.md
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
- Comments: what viewers said under a recording, comments and replies in one list,
  newest first. Reported ones lead the State filter: a report has already taken them
  down, so they are the only rows anybody is waiting on, and the rail badge counts
  them. Approve puts one back for good; delete takes it and its replies. A comment's
  own page carries the reports against it, the thread around it, and the three
  decisions - approve, delete, ban the author - because the panel is where the work
  is done and the buttons on the watch page are only the shortcut for whoever is
  already reading it. Banning writes an ordinary chat ban: the comment box already
  refuses anyone chat has silenced, so a second kind would be a second thing to
  remember to lift The watch page is where a single comment is dealt with; this is the
  sweep - a run of spam under one recording, or one account busy across several.
  Deleting a comment takes its replies. Reading is open to anyone past
  `access-manage`; a viewer deletes their own anywhere, moderators delete any
- Feedback: what viewers sent in from the site - the Feedback button in the top bar
  and "Report" on the player. Each report carries the browser, screen, connection and
  player snapshot the client collected, bounded by `App\Support\Diagnostics`, plus an
  optional Telegram handle for a follow-up. Reading is open to anyone past
  `access-manage`; triaging and deleting needs `stream.manage`
- Settings: one pane per group in `config/settings.php`, each with its own URL and its
  own entry in the menu down the left. Branding, login copy, accent colour, footer links,
  the announcement, the feature switches and the control key. Events and Categories join
  the same menu by hand, being sets of rows rather than sets of knobs

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