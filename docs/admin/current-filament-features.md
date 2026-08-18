# Current Filament Admin: Feature Inventory

Snapshot of everything `/admin` does today. This is the parity contract for the Inertia rebuild: every row here must have either a replacement or an explicit "dropped, because X" decision.

Source of truth: `app/Filament/**`, `app/Providers/Filament/AdminPanelProvider.php`, `resources/views/filament/**`.

## 1. Panel shell

`app/Providers/Filament/AdminPanelProvider.php`

| Aspect | Current value |
|---|---|
| Path | `/admin`, default panel |
| Auth | Filament's own `->login()` screen at `/admin/login` (separate from the app's OIDC login at `/login`) |
| Gate | `User::canAccessPanel()` — `hasPermission('filament.access') || isStaff()` (`app/Models/User.php:378`) |
| Brand | Brand name from `BrandingService`, `favicon.svg` |
| Theme | primary = Purple, gray = Slate, sidebar collapsible on desktop, `maxContentWidth('100%')` |
| Nav groups | Streaming, Infrastructure, User Management, Chat (+ an undeclared `Content` group used by RecordingResource) |
| Dashboard | Filament default `Dashboard` page + `AccountWidget` + `FilamentInfoWidget` + all auto-discovered widgets |

Notes / bugs to carry over consciously:
- `RecordingResource` declares `navigationGroup = 'Content'`, which is not in `navigationGroups()`. It renders as an ad-hoc group.
- Guests hitting `/admin` redirect to `/admin/login`, not to the app's OIDC flow. Two login screens exist.
- `HandleInertiaRequests` already shares `auth.can_access_filament` to the SPA (`app/Http/Middleware/HandleInertiaRequests.php:78`) using `$user->can('filament.access')`, a *different* check than `canAccessPanel()`. The rebuild should unify these.

## 2. Resources

### 2.1 Sources (`SourceResource`) — Streaming, sort 1

Model `Source`. Nav badge = count of `ONLINE` sources, green.

Form:
- Section "Basic Information" (2 col): `name` (required, live-on-blur, auto-slugs into `slug` **on create only**), `slug` labelled "Stream Name" (required, unique ignoring record, **disabled on edit** — it is the RTMP ingress path and the HLS route key, so changing it disconnects OBS and breaks playback; `Source::updating()` also reverts any slug write), `priority` (numeric 0..999, "higher first on homepage"), `description` textarea (full width). **No `status` field**: status is edited only through the table's Update Status action, so there is one path.
- Section "Stream Configuration" ("OBS Studio Configuration"): two read-only placeholders rendering raw HTML — `getRtmpServerUrl()` and `getObsStreamKey()` — each click-to-copy via inline `navigator.clipboard` JS. Both show "Will be generated on save" for new records. Below them, a form action **Regenerate Stream Key** (visible on edit only, confirm modal, `Str::random(32)`, saves, warns that active streams disconnect) sits next to the key it replaces.

Table (poll 10s, default sort `priority` desc):
- `status` badge with per-state color + heroicon (signal / signal-slash / exclamation-triangle)
- `name` (searchable, sortable, bold)
- `slug` labelled "Stream Name", badge, copyable, searchable
- `priority` badge, sortable
- `shows_count` (relation count) "Total Shows", sortable
- `live_shows_count` computed via `liveShows()->count()`, badge green when > 0
- `created_at`, `updated_at` — hidden by default, toggleable

Filters: `status` select ("All statuses" placeholder).

Row actions:
- **Update Status** — modal form with a status select prefilled from the record; saves and relies on the model observer to broadcast; success toast. This is the *only* way to change a source status.
- Edit
- Delete — blocked with a danger toast if `liveShows()` exists.

Bulk actions (grouped): **Update Status** (same modal, applied to each, deselects after), Delete (blocked if any selected source has live shows).

`EditSource` header actions: Delete only.

Relation manager — **Shows**: columns `title`, `status` badge, `scheduled_start`, `viewer_count`; header Create; row Edit/Delete; bulk Delete.

### 2.2 Shows (`ShowResource`) — Streaming, sort 2

Model `Show`. Nav badge: `"{n} live"` (green) else `"{n} upcoming"` (amber) else none.

Form sections:
1. **Show Information** (2 col): `title` (live-on-blur; on create only, sets `slug` = `Str::slug(title-YYYY-MM-DD)`), `slug` (unique), `source_id` select (required, options from `Source::ordered()`, searchable, preload), `server_id` select (optional, only `status = available` servers, by hostname), `description` textarea full width.
2. **Schedule** (2 col, all `Europe/Berlin`, seconds off): `scheduled_start` (required), `scheduled_end` (required, `after:scheduled_start`), `actual_start`, `actual_end`.
3. **Status & Settings** (2 col): `status` select (scheduled/live/ended/cancelled, **disabled while live**), `auto_mode` toggle, `recordable` toggle, `required_roles` checkbox list (options = `Role::pluck('name','slug')`, empty = public), `thumbnail_path` file upload (image, S3 disk, `shows/thumbnails`, max 5 MB, jpeg/png/webp, private visibility, preserve filenames, 250px preview, custom state loader), `tags` tags-input with 7 suggestions (Main Stage, Panel, Workshop, Performance, Interview, Opening Ceremony, Closing Ceremony).
4. **Statistics** (3 col, only on edit): read-only `viewer_count`, `peak_viewer_count`, `formatted_duration`.
5. **Additional Configuration** (collapsed): `metadata` key/value editor.

Table (poll 5s, default sort `scheduled_start` asc):
- `thumbnail_url` image column (square, 40px) — uses the signed-URL accessor, not the raw path
- `title` (searchable, sortable, bold)
- `source.name` badge, searchable, sortable
- `status` badge — colors live/scheduled/ended/cancelled + icons
- `scheduled_start` `M j, Y H:i`, sortable
- `actual_start` "Went Live", placeholder "Not started", toggleable
- `viewer_count` badge (green when > 0), numeric, sortable
- `peak_viewer_count` "Peak", hidden by default
- `auto_mode` rendered as `Auto`/`Manual` badge with cog / hand-raised icons
- `required_roles` rendered as `Restricted`/`Public` badge via `hasAccessRestriction()`, lock / globe icons, hidden by default
- `tags` badge list, comma separated, hidden by default

Filters: `hide_ended` (**on by default**, `status != ended`), `status` multi-select, `source` relationship select, `today` (`Show::today()` scope), `upcoming` (`Show::upcoming()` scope).

Row actions:
- **Go Live** — visible only when `scheduled`; confirm modal ("will mark it as live and notify viewers"); calls `$show->goLive()`; success toast.
- **End Stream** — visible only when `live`; confirm modal; calls `$show->endLivestream()`.
- **View Statistics** — links to the custom statistics page.
- Edit
- Delete — blocked with a danger toast when `status === 'live'`.

Bulk actions (grouped): **Cancel Shows** (calls `cancel()` on each `scheduled` record), Delete (blocked if any selected show is live).

Header action: **Live Dashboard** — opens the Stream Control page in a new tab.

Custom page actions — `EditShow`: **Capture Screenshot** (disabled unless status is `live` *and* a source is assigned; tooltip explains which precondition failed; calls `$show->captureScreenshot()`, refills the form, distinct toasts for success / null result / exception) + Delete.

Custom page — `ViewShowStatistics` (`/admin/shows/{record}/statistics`): title "Statistics for {title}"; data from `ShowStatisticsService::getShowStatistics()` plus `getRealtimeStats()` when live. Blade renders: 4 stat cards (current / peak / average / total unique viewers, with a "● Live" marker), a Broadcast Information definition list (scheduled + actual start/end, duration), and further sections in `resources/views/filament/resources/show-resource/pages/view-show-statistics.blade.php`.

Relation manager — **Viewers** (`viewerSessions`): `user.name`, `user.email`, `joined_at`, `left_at` (placeholder "Still watching"), computed `watch_duration` formatted `XhYmZs`, `ip_address` (hidden by default), `is_active` → `Active`/`Inactive` badge. Filter: "Currently Watching" (`active()` scope). No actions — read-only.

### 2.3 Servers (`ServerResource`) — Infrastructure, sort 10

Model `Server`, slug `servers`. No nav badge.

Form (single column): `hetzner_id` (disabled on edit), `hostname` (required), `ip`, `port` (numeric 1..65535, default 8080), `shared_secret` (disabled on edit, defaults to `Str::random(40)`, required), `type` select origin/edge (disabled on edit, default edge), `max_clients` (numeric, **only shown for edge**, default 100), `status` select (provisioning/active/deprovisioning/deleted/error, default active — manual override), `immutable` checkbox (edge only, default true, prevents autoscaler deletion), read-only `created_at` / `updated_at` as `diffForHumans()`.

Table (`->poll()` default interval; query excludes `status = deleted`):
- `hetzner_id` "Server ID", searchable, `-` fallback
- `type` badge (origin = amber, edge = green)
- `hostname` searchable + copyable
- `ip` copyable
- `port` sortable
- `status` badge with 5-state color map
- `viewer_count` "Viewers" badge — shows `-` for origin; description line shows `N% capacity` for edge with `max_clients > 0`
- `last_heartbeat` icon column — check/x by `hasRecentHeartbeat()`, tooltip with `diffForHumans()`
- `health_status` badge (healthy/unhealthy/unknown), tooltip with last check time + `health_check_message`, edge only
- `max_clients` sortable

Filters: `status` multi-select, `type` select.

Row actions: Edit; **Install Script** (links to custom page); **Deprovision** (confirm, only when `hetzner_id` present, calls `$server->deprovision()`); **Delete** (confirm, only for manual servers with no `hetzner_id`).

`ListServers` header actions:
- **New Manual Server** (create)
- **Enable Autoscaler** / **Disable Autoscaler** — mutually exclusive by `AutoscalerService::isAutoscalerEnabled()`, green / red
- **Provision Cloud Server** — modal with a type select (`Origin (ccx43 - High Performance)` / `Edge (cpx21 - Standard)`); refuses a second origin if one is active or provisioning (danger toast); otherwise creates a `provisioning` server row (hostname `pending`, port 443, random secret, max_clients 1000 origin / 100 edge) and dispatches `CreateVirtualMachineJob`.

Custom page — `ViewInstallScript` (`/admin/servers/{record}/install-script`): title `Install Script - Server #{id} ({type})`. Generates the install script and cloud-init via `ServerProvisioningService`, then regex-extracts embedded configs into tabs: docker-compose, SRS conf, nginx (origin or edge), Caddyfile, plus FFmpeg placeholders for origin. Tab state in `activeTab`. Header actions: **Copy Install Script** (toast; copy done in JS), **Download Script** (`streamDownload` as `install-{id}.sh`), **Regenerate Scripts** (confirm; backfills `shared_secret` if missing).

Relation manager — `UserRelationManager` (`user`): columns `sub`, `name`. **Declared in the file but not returned by `getRelations()`, so it is dead code today.**

### 2.4 Users (`UserResource`) — User Management, sort 20

Model `User`, slug `users`. Globally searchable on `name`.

Form: `sub` (disabled, required), `name` (disabled, required), `reg_id` (disabled, integer), `server_id` select (relationship on `server.hostname`, filtered to edge + active), read-only `updated_at` / `created_at` as `diffForHumans()`.

Table: `sub`, `name` (searchable, sortable), `reg_id`. No filters, no row actions, no bulk actions. `ListUsers` has a Create header action; `EditUser` has Delete.

Relation managers:
- **Roles**: `name` (bold), `slug` badge, `chat_color` color column (copyable), `priority` badge with a 4-tier color ramp, `assigned_at_login` toggle (disabled/read-only). Header **Attach** (role select from `Role::ordered()`, preloaded, success toast). Row **Detach** with confirm modal. Bulk Detach with confirm. Sort `priority` desc, paginate 10/25/50.
- **Messages**: `user.name`, `message`, `is_command`. Form exists (user select, message, is_command, timestamps) but no header/row actions are wired, so it is read-only in practice.

### 2.5 Roles (`RoleResource`) — User Management, sort 21

> **Warning: parts of this resource no longer work.** Migrations on 2025-08-29 dropped
> `roles.is_staff` (`remove_is_staff_from_roles_table`) and `role_user.assigned_at`,
> `role_user.expires_at`, `role_user.assigned_by` (`simplify_role_user_table`). The resource
> was never updated, so its `is_staff` toggle and toggle column, and its Users relation
> manager's pivot columns, Active/Expired filters and attach form, all address columns that
> do not exist. They are described below as written, not as working. See
> `remaining-modules.md` §1 for what the replacement builds instead.

Model `Role`. Nav badge = total role count.

Form sections:
1. **Role Information** (2 col): `name` (live-on-blur → slug), `slug` (unique, "used for system identification"), `description` textarea.
2. **Chat Appearance** (3 col): `chat_color` color picker (default `#808080`), `priority` numeric (guidance: 100 admin, 90 moderator), `is_visible` toggle "Show in Chat".
3. **Settings** (2 col): `assigned_at_login` toggle (default true — synced from the registration system at login; off = persists), `is_staff` toggle, `permissions` tags input with suggestions `filament.access`, `admin.access`, `chat.moderate`, `chat.delete`, `chat.timeout`, `chat.slowmode`, `stream.manage`, `user.manage`.
4. **Additional Configuration** (collapsed): `metadata` key/value.

Table (default sort `priority` desc):
- `name` bold, `slug` badge, `chat_color` color column (copyable, "Color copied" for 1.5 s)
- `priority` badge with tiered colors (>=100 red, >=90 amber, >=50 blue, else gray)
- `assigned_at_login` → `Auto-synced` / `Manual` badge with an explanatory tooltip
- `is_staff` **inline toggle column** (writes on click)
- `is_visible` **inline toggle column** ("Chat Badge") with tooltip
- `users_count` relation count badge, sortable
- `created_at` hidden by default

Filters: three ternary filters — `is_staff`, `assigned_at_login`, `is_visible`, each with custom true/false/placeholder labels.

Row actions: Edit; Delete blocked with a danger toast when the role has users. Bulk Delete has the same guard.

Header action: **Create Default Roles** — visible only when `Role::count() === 0`; confirm modal; seeds Admin / Moderator / Super Sponsor / Sponsor / Attendee with fixed colors, priorities, `assigned_at_login`, `is_staff` and permission sets (see `RoleResource::createDefaultRoles()` for the exact payload — this is behaviour the rebuild must reproduce verbatim).

Relation manager — **Users**: `name`, `email`, `pivot.assigned_at`, `pivot.expires_at` (placeholder "Never"), `pivot.assigned_by` badge (manual = green, login = amber, system = blue). Filters "Active Only" (`expires_at` null or future) and "Expired Only". Header **Attach** with a form of record select + `expires_at` datetime (Europe/Berlin, "empty = permanent") + `assigned_by` select (manual/system); sets `assigned_at = now()`; success toast. Row Detach + bulk Detach.

### 2.6 Emotes (`EmoteResource`) — Chat, sort 30

Model `Emote`. Nav badge = pending count, amber when > 0, hidden at 0.

Form:
1. **Emote Information** (2 col): `name` (required, unique, regex `^[a-z0-9_]+$`, max 20), `s3_key` file upload (image, cover resize, 1:1 crop, 64×64 target, S3 disk, `emotes/`, private, preserve filenames, custom state loader), `is_global` toggle ("If disabled, only the uploader can use this emote"), `is_approved` toggle.
2. **Metadata** (2 col, all disabled + `dehydrated(false)`): `uploadedBy`, `approvedBy`, `approved_at`, `usage_count`.

Table (default sort `created_at` desc): `url` image (40px, square), `name` formatted as `:name:` (searchable, copyable), `uploadedBy.name` searchable, `is_global` boolean icon, `is_approved` boolean icon (green/amber), `usage_count` numeric sortable, `created_at` "Uploaded" toggleable.

Filters: `approval_status` custom select (Pending Approval / Approved), `is_global` ternary.

Row actions: **Approve** (only when not approved, confirm, `$emote->approve(auth()->user())`); **Reject** (only when not approved, confirm, "permanently delete the emote and its image", `$emote->reject()`); Edit; Delete.

Bulk actions: **Approve Selected**, **Reject Selected** (both skip already-approved), and a grouped Delete.

Create/Edit hooks: `CreateEmote` stamps `uploaded_by_user_id` and, if created pre-approved, `approved_by_user_id` + `approved_at`. `EditEmote` stamps approver + timestamp on the first transition to approved.

### 2.7 Recordings (`RecordingResource`) — "Content", sort 50

Model `Recording`. No nav badge.

Form (single column): `show_id` select (options are `"{title} ({source.name})"`, searchable, preload, nullable, reactive — **on change it auto-fills `title`, `description`, `date` from `actual_start`, and `duration` from `actual_end - actual_start`**), `title` (reactive → slug on create), `slug` (unique), `description`, `date` datetime (required, non-native), `duration` numeric with `seconds` suffix ("auto-filled via ffmpeg if empty"), `m3u8_url` (required, URL), `thumbnail_path` upload (image, cover resize to 1280×720, S3 `recordings/thumbnails`, private, "leave empty to auto-generate from first frame"), `is_published` toggle (default true), `required_roles` checkbox list.

Table (default sort `date` desc): `thumbnail_url` image (80×45), `title`, `slug` (toggleable), `show.title` badge, `date` `M j, Y H:i`, `duration` formatted `H:MM:SS` / `M:SS` with `-` fallback, `views` numeric, `is_published` boolean icon, `required_roles` → Restricted/Public badge (hidden by default), `created_at` (hidden by default).

Filters: `is_published` ternary.

Row actions: **Regenerate Thumbnail** (visible when `m3u8_url` present; confirm; nulls `thumbnail_path` + `thumbnail_capture_error`, saves, dispatches `ProcessRecordingJob`, toast telling the user to refresh); Edit; Delete. `EditRecording` has the same action plus a redirect back to the edit page. Bulk: **Regenerate Thumbnails** (counts how many had an `m3u8_url`), Delete.

## 3. Custom pages (non-resource)

### 3.1 Stream Control (`Pages/Stream`) — Streaming, sort 3, `/admin/stream`

Blade view is empty (`<x-filament::page>`); all content comes from header actions + header widgets.

Header actions, each with a confirm modal and a tooltip, firing `StreamStatusEvent`:
| Action | Event value | Tooltip |
|---|---|---|
| Set Stream Starting Soon (Start Servers) | `STARTING_SOON` | "Will start servers, takes around 6 minutes." |
| Set Stream Online | `ONLINE` | "Set this after you started the stream in obs for the first time." |
| Set Stream Technical Issue | `TECHNICAL_ISSUE` | "…will automatically activate upon stream disconnect." |
| Set Stream Offline (Delete Servers) — red | `OFFLINE` | "This sets the stream fully offline and deletes ALL Servers." |

Header widgets: `ServerActive`, `Capacity`, `ViewCountChart`.

### 3.2 Branding (`Pages/Branding`) — Streaming, sort 9

Form-only settings page over `BrandingSetting` / `BrandingService::EDITABLE`. Helper text for each field comes from `BrandingService::EDITABLE[$key]`.

Sections:
1. **Identity** (2 col): `convention_name`*, `site_name`*, `identity_name`*, `identity_register_url` (url), `identity_logout_url` (url)
2. **Login screen** (2 col): `login_eyebrow`, `login_headline`*, `login_tagline`, `login_button_label`*, `login_body` textarea, `login_features` textarea
3. **Look** (2 col): `primary_color` color picker, `logo_path` upload (public disk, `branding/`, image editor enabled), `login_background_image` upload, `login_background_video` upload (mp4/webm)
4. **Footer links** (3 col): `support_url`, `imprint_url`, `privacy_url`

Actions: **Save changes** (writes each `EDITABLE` key via `BrandingSetting::setValue`, toast) and **Reset to defaults** (confirm modal; deletes every `BrandingSetting` row, refills from `config/branding.php`; uploaded files are kept).

## 4. Widgets

| Widget | Type | Poll | Content |
|---|---|---|---|
| `ServerActive` | stats cards | 10s | One card per edge-server status: `Edge Server {status}` = count (grouped query) |
| `Capacity` | stats cards | 10s | Max clients (sum `max_clients` over active edge), Booting Capacity (same over provisioning edge), Waiting Users (open `source_users` rows with no edge; was `users.server_id IS NULL` until 18 Aug 2026) |
| `ViewCountChart` | line chart | none | the last 7 days as series, hourly average of `ViewCount.count` via `Flowframe\Trend`, x-axis = 24 fixed hour labels |

`ViewCountChart` is stale — the dates are hardcoded to September 2023. The rebuild should make the range dynamic (per-event or last-N-days); flag it as a deliberate behaviour change rather than parity.

## 5. Cross-cutting behaviour the rebuild must reproduce

1. **Polling.** Sources 10s, Shows 5s, Servers default, widgets 10s. In Inertia these become `router.reload({ only: [...] })` on an interval or Echo-driven refreshes.
2. **Toasts.** Every mutating action emits a titled success/danger notification with a body. Needs a flash-message → toast pipeline in Inertia.
3. **Guarded deletes.** Shows (live), Sources (live shows), Roles (assigned users) block deletion with a danger toast instead of failing silently. These belong in policies/validation server-side so tests can assert them.
4. **Private S3 uploads.** `thumbnail_path`, `s3_key` are stored on the private `s3` disk and read back through signed-URL accessors (`thumbnail_url`, `url`). Filament's `FileUpload` handles the upload; Inertia needs an explicit upload endpoint plus the same signed-URL accessors.
5. **Enum casts.** `Source.status`, `Server.status`, `Server.type` are enum-cast, so several closures do `$state?->value ?? $state`. Serialize enums explicitly in props.
6. **Live-derived slugs.** Sources, Shows, Roles, Recordings all auto-slug from the name/title on the client while typing, and only on create for Shows/Recordings.
7. **Column visibility + persistence.** Many columns are `toggleable(isToggledHiddenByDefault: true)`. Filament persists this per user in the session. Decide whether to reimplement or drop.
8. **Default-on filter.** The Shows table hides ended shows unless the filter is switched off. Easy to lose in a rewrite.
9. **Inline toggle columns.** Roles' `is_staff` and `is_visible` write immediately on click from the table.
10. **Global search.** Only `User.name` is globally searchable (`ServerResource` explicitly opts out).
11. **Relation-manager pivot editing.** Role↔User attach carries `expires_at` and `assigned_by` pivot data plus an `assigned_at` stamp.
12. **Two auth entry points.** `/admin/login` (Filament) vs `/login` (OIDC). The rebuild should collapse to the OIDC flow plus an authorization gate.

## 6. Known gaps / dead code found during the audit

- `ServerResource/RelationManagers/UserRelationManager` is never registered.
- `UserResource` messages relation manager has a form but no way to open it.
- `RecordingResource` uses an undeclared nav group (`Content`).
- `ViewInstallScript` regex-extracts config blocks out of a generated shell script; the FFmpeg tabs are hardcoded placeholder comments.
- `ViewCountChart` uses hardcoded 2023 dates.
- `tests/Feature/Filament/AdminPanelTest.php` asserts on text that no longer exists in the form (`'Leave empty for locally managed servers'`), so parity claims based on it are unreliable until re-run.
