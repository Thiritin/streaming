# Admin Rebuild Plan: Filament -> Inertia v2

Companion to [`current-filament-features.md`](./current-filament-features.md), which is the parity contract. This file is the design spec plus the build/verify/cutover plan.

Decisions taken (2026-07-30):

| Decision | Choice |
|---|---|
| Visual direction | **Control Room**, dark-first |
| Cutover | **Parallel `/manage`**, delete `/admin` once the parity suite is green |
| Test depth | **Server-side parity tests per module + a small Playwright smoke set** |

Baseline recorded before any change: `php artisan test tests/Feature/Filament/AdminPanelTest.php` -> 16 passed, 1 failed (`admin can create server` asserts helper text `Leave empty for locally managed servers`, which the form no longer contains). That assertion is stale, not a regression.

---

## Part 1 - Design spec: Control Room

### 1.1 Intent

An operator watching a live event needs three things at a glance: is the stream up, is there enough edge capacity, and which shows are live right now. Everything else is CRUD that must not get in the way. So: high information density, a status strip that never scrolls away, numbers in tabular figures, colour reserved for state (never decoration).

Neutral by construction. No Eurofurence wordmark, artwork or copy in the chrome. Brand name and logo come from `BrandingService` at runtime, same as the public site, so a different convention rebrands it without touching components.

### 1.2 Layout skeleton

```
┌──────────────────────────────────────────────────────────────────────┐
│ ● OFFLINE   ▶ 3 live   ▤ 4/6 edge   ◉ 12 480 viewers      user  ↗ │  h-10, sticky
├────┬─────────────────────────────────────────────────────────────────┤
│ ▣  │  Shows                                        [ + New Show ]    │  h-14 page header
│ ▤  │ ─────────────────────────────────────────────────────────────── │
│ ▥  │  status ▾   source ▾   ☑ hide ended            ⌕ search         │  h-11 filter bar
│ ▦  │ ─────────────────────────────────────────────────────────────── │
│ ▧  │  STATUS   TITLE             SOURCE    VIEW    PEAK    SCHED     │  h-8 header row
│    │  ● LIVE   Opening Ceremony  main      4 812   5 120   10:00     │  h-8 rows
│    │  ○ SCHED  Dealers Den Tour  stage-b       –       –   11:30     │
│ ⚙  │ ─────────────────────────────────────────────────────────────── │
│    │  1–25 of 48                                     ‹ 1 2 3 ›       │
└────┴─────────────────────────────────────────────────────────────────┘
  240px sidebar           fluid content, no max-width
```

- **Status strip** (`ManageStatusStrip.vue`): live show count, edge servers active/total, total viewers, and the current stream status from `StreamStatusEnum`. Polls independently of the page body via a partial reload of one prop. Clicking a segment deep-links to the relevant module.
- **Sidebar** (`ManageSidebar.vue`): permanent, 240px, always labelled, no collapse. An icon rail saves 180px and costs a guess on every click; this panel is used to edit things. Groups: Streaming / Infrastructure / User Management / Chat / Content / Settings. Badge counts sit at the right of their row (live shows, online sources, pending emotes, role count).
- **Page header**: title, optional subtitle, right-aligned primary + secondary actions. No breadcrumbs; the sidebar plus a title is enough at this depth.
- **Content**: fluid width, matching today's `maxContentWidth('100%')`.
- Detail pages are full pages with tabs, not drawers (the Cockpit option was not selected). Tabs are real URLs so they are linkable and testable: `/manage/shows/{show}` and `/manage/shows/{show}/statistics`, `/manage/shows/{show}/viewers`.

### 1.3 Tokens

`resources/css/app.css` already defines an `oklch` primary ramp at hue ~181 (teal/cyan) plus `--background`, `--foreground`, `--border`, `--ring` with a `.dark` block. The admin extends that, it does not fork it. Per the project rule, no `-gray-` utilities: neutral surfaces come from named tokens below and from `primary-900/950` where a tinted surface is wanted.

Add to `@theme` and to both `:root` and `.dark`:

```css
--surface-0   /* app background, deepest */
--surface-1   /* rail, status strip */
--surface-2   /* cards, table header, filter bar */
--surface-3   /* row hover, popovers */
--fg-1        /* primary text */
--fg-2        /* labels, secondary */
--fg-3        /* placeholders, disabled */
--hairline    /* 1px separators */
--state-live      /* primary-400, cyan */
--state-ok        /* green  */
--state-warn      /* amber  */
--state-danger    /* red    */
--state-idle      /* fg-3   */
```

Dark is not a mode here, it is the palette: the surface, foreground and state tokens hold their dark values at `:root`, so there is no class to set and no flash on first paint. `.manage-root` also sets `color-scheme: dark` so native controls (selects, checkboxes, date pickers, scrollbars) are drawn dark instead of in the OS light palette. A `.manage-light` block is kept unused in case a toggle is ever wanted.

Status colour mapping is centralised once, server-side, so the table, badges and status strip can never drift:

| Domain state | Token | Glyph |
|---|---|---|
| show live, source online, server active, session active, healthy | `state-live` / `state-ok` | `●` |
| scheduled, provisioning, pending approval, booting | `state-warn` | `○` / `◐` |
| ended, deleted, inactive, unknown | `state-idle` | `○` |
| cancelled, error, unhealthy, deprovisioning | `state-danger` | `▲` |

### 1.4 Typography and density

- UI text 13px/18px, labels 11px uppercase with 0.06em tracking, page titles 18px semibold.
- All numerics `font-variant-numeric: tabular-nums`, right-aligned in tables, thin space as thousands separator so digits do not jump while polling.
- Table rows 32px, header 28px, cell padding `px-3`. Comfortable mode (40px rows) behind a per-user preference is a nice-to-have, not phase 1.
- IDs, hostnames, stream keys, slugs and script bodies in the mono stack.

### 1.5 Component inventory to build

Under `resources/js/Components/Manage/`. Everything composes the existing `Components/ui/*` (reka-ui based) primitives already in the repo; new files are layout and data-display only.

| Component | Responsibility |
|---|---|
| `ManageLayout.vue` | dark root, status strip, sidebar, page content slot, toast host |
| `ManageSidebar.vue` | permanent nav: groups, badge pills, active section |
| `ManageStatusStrip.vue` | global KPIs, own poll interval |
| `PageHeader.vue` | title, subtitle, action slots |
| `DataTable.vue` | renders a server-provided column set; sort links, row click, sticky header, empty state, loading shimmer during partial reloads |
| `DataTableColumnToggle.vue` | show/hide toggleable columns, persisted per user |
| `FilterBar.vue` | select / ternary / boolean-toggle / search filters, all bound to query string |
| `Pagination.vue` | page links + per-page select |
| `StatusBadge.vue` | takes the server-computed `{label, tone, icon}` triple |
| `StatCard.vue` | stat tiles (statistics page, later Stream Control) |
| `ActionButton.vue` | POST/DELETE with optional confirm dialog, disabled/tooltip reasons |
| `ConfirmDialog.vue` | modal heading/description/submit label, mirroring Filament's confirm modals |
| `FormSection.vue`, `FormField.vue`, `FormActions.vue` | one column of `label: control` rows, sticky save bar, collapsible variant |
| `ScheduleRow.vue` | start / editable duration / end on one line |
| `FileUploadField.vue` | image/video upload against the upload endpoint, preview, replace, remove |
| `CheckboxList.vue`, `ColorPicker.vue` | the Filament field types with no shadcn equivalent (tags and key/value were dropped with the fields that used them) |
| `CopyableText.vue` | click-to-copy with confirmation, replaces the inline `onclick` HTML in `SourceResource` |
| `Toast.vue`, `useToasts.js` | flash -> toast |
| `CodeBlock.vue` | mono, wrap toggle, copy, download (install script tabs) |
| `RelationPanel.vue` | embedded table + attach/detach for relation-manager equivalents |

`lucide-vue-next`, `floating-vue`, `dayjs`, `radix-vue`/`reka-ui` are already installed. Add nothing but a chart library if `ChartLine.vue` needs one; prefer inline SVG for a single line chart and skip the dependency.

---

## Part 2 - Architecture

### 2.1 Routing

New file `routes/manage.php`, required from `RouteServiceProvider`, prefix `/manage`, names `manage.*`, middleware `['web', 'auth:web', 'can:access-manage']`.

```
GET    /manage                           manage.dashboard
GET    /manage/sources                   manage.sources.index
GET    /manage/sources/create            manage.sources.create
POST   /manage/sources                   manage.sources.store
GET    /manage/sources/{source}          manage.sources.edit
PUT    /manage/sources/{source}          manage.sources.update
DELETE /manage/sources/{source}          manage.sources.destroy
POST   /manage/sources/{source}/status           manage.sources.status
POST   /manage/sources/{source}/stream-key       manage.sources.stream-key
POST   /manage/sources/bulk/status               manage.sources.bulk.status
DELETE /manage/sources/bulk                      manage.sources.bulk.destroy
...same shape for shows, servers, users, roles, emotes, recordings
GET    /manage/shows/{show}/statistics   manage.shows.statistics
GET    /manage/shows/{show}/viewers      manage.shows.viewers
POST   /manage/shows/{show}/go-live      manage.shows.go-live
POST   /manage/shows/{show}/end          manage.shows.end
POST   /manage/shows/{show}/screenshot   manage.shows.screenshot
GET    /manage/servers/{server}/install-script         manage.servers.install-script
GET    /manage/servers/{server}/install-script/download
POST   /manage/servers/{server}/deprovision
POST   /manage/servers/provision                 manage.servers.provision
POST   /manage/autoscaler/{state}                manage.autoscaler
GET    /manage/stream                    manage.stream
POST   /manage/stream/status             manage.stream.status
GET    /manage/branding                  manage.branding
PUT    /manage/branding                  manage.branding.update
DELETE /manage/branding                  manage.branding.reset
POST   /manage/uploads                   manage.uploads.store
```

Every mutation is a POST/PUT/DELETE that redirects back with a flash. No JSON endpoints, no `fetch()` - this satisfies the project rule that data flows through Inertia props. Uploads are the one exception and go through Inertia's own multipart form support, still returning a redirect.

### 2.2 Authorization

Today's gate is `User::canAccessPanel()` = `hasPermission('filament.access') || isStaff()`, while `HandleInertiaRequests` shares `auth.can_access_filament` from `$user->can('filament.access')`. Two different checks.

Unify:
- `Gate::define('access-manage', fn (User $u) => $u->hasPermission('admin.access') || $u->hasPermission('filament.access') || $u->isStaff())` in `AuthServiceProvider`, keeping `filament.access` accepted so existing role rows keep working.
- Add real policies: `ShowPolicy`, `SourcePolicy`, `ServerPolicy`, `UserPolicy`, `RolePolicy`, `EmotePolicy`, `RecordingPolicy`. The guarded deletes move here (`ShowPolicy::delete` false while live; `SourcePolicy::delete` false with live shows; `RolePolicy::delete` false with users) so both the UI and the tests read the same rule.
- Fine-grained action permissions map to the existing strings: `stream.manage` for go-live / end / stream status / provisioning / autoscaler, `user.manage` for users and roles, `chat.moderate` for emotes.
- Rename the shared prop to `auth.can_access_manage` and keep `can_access_filament` as an alias until `/admin` is gone.
- No `/manage/login`. Guests are redirected into the existing OIDC flow at `/login`; a signed-in user without the gate gets 403. This is a deliberate change from Filament's second login screen.

### 2.3 List pages: one prop contract

Every index page ships the same envelope so `DataTable.vue` and `FilterBar.vue` stay generic and the tests can assert on a stable shape:

```php
[
  'rows'    => [...],                  // already formatted for display
  'columns' => [                       // server-declared, ordered
      ['key' => 'status', 'label' => 'Status', 'type' => 'badge',
       'sortable' => false, 'toggleable' => false, 'hiddenByDefault' => false],
      ['key' => 'viewer_count', 'label' => 'Viewers', 'type' => 'number',
       'align' => 'right', 'sortable' => true],
  ],
  'filters' => [                       // declared once, rendered generically
      ['key' => 'status', 'type' => 'select', 'label' => 'Status',
       'options' => [...], 'multiple' => true, 'value' => [...]],
      ['key' => 'hide_ended', 'type' => 'boolean', 'label' => 'Hide ended',
       'value' => true, 'default' => true],
  ],
  'sort'    => ['key' => 'scheduled_start', 'dir' => 'asc'],
  'search'  => 'opening',
  'meta'    => ['page' => 1, 'perPage' => 25, 'total' => 48],
  'rowActions'  => [...],              // per-row, already visibility-filtered
  'bulkActions' => [...],
  'pageActions' => [...],
]
```

A small `App\Support\Manage\Table` builder produces this from a query plus a column/filter declaration, so the seven modules do not each hand-roll sorting, searching, filtering and pagination. Column `type` values: `text`, `number`, `badge`, `image`, `bool`, `datetime`, `duration`, `color`, `copyable`, `toggle`.

Two things that are easy to lose and must be explicit in the declaration:
- `hide_ended` on Shows defaults to **on**.
- `hiddenByDefault` columns (peak viewers, tags, access, timestamps, IP) keep that flag, and the user's choice persists in the session keyed by table name.

Badges never carry colour logic on the client. The server sends `['label' => 'LIVE', 'tone' => 'live', 'icon' => 'signal']` and `StatusBadge.vue` looks the tone up in the token map from 1.3.

### 2.4 Polling

Inertia v2's `usePoll` replaces Filament's `->poll()`:

```js
usePoll(5000, { only: ['rows', 'meta'] })          // Shows index
usePoll(10000, { only: ['rows', 'meta'] })         // Sources index
usePoll(15000, { only: ['stats'] })                // widgets
usePoll(10000, { only: ['status'] })               // status strip, in ManageLayout
```

Rules: only ever reload the data props, never `columns`/`filters`/`rowActions`; pause while a form is dirty or a dialog is open (`usePoll`'s stop/start); pause when the tab is hidden. Where Echo already broadcasts (source status, stream status, show status), subscribe and trigger a single reload instead of adding a second interval - the channels exist in `routes/channels.php` and `bootstrap.js` already wires Echo.

### 2.5 Actions, confirms and toasts

Filament's action objects have five parts: label, icon, colour, visibility predicate, confirm modal copy. Model that server-side per row so the client stays dumb and the tests can assert visibility:

```php
['name' => 'go_live', 'label' => 'Go Live', 'icon' => 'signal', 'tone' => 'ok',
 'method' => 'post', 'url' => route('manage.shows.go-live', $show),
 'confirm' => [
     'heading' => 'Start Live Stream',
     'description' => 'Are you sure you want to start this show? This will mark it as live and notify viewers.',
     'submit' => 'Go Live',
 ],
 'disabledReason' => null]
```

`disabledReason` carries the tooltip text Filament shows for the disabled screenshot button ("Show must be live to capture screenshot" / "Show must have a source"), so that behaviour survives.

Toasts ride Inertia's own flash bag rather than a shared prop. `App\Support\Manage\Toast` writes `{tone, title, body}` under Inertia's flash session key; `inertia-laravel` then attaches it to the response. Same titles and bodies as today - the audit doc lists them and the tests assert them.

Three things about that mechanism, all verified against the installed `inertia-laravel` 2.0.19 and `@inertiajs/vue3` 2.1.3:

1. **`flash` is a top-level key on the page object, not a prop** (`Response::toResponse` merges `resolveFlashData()` as a sibling of `props`). So the client reads `usePage().flash?.toast`, and a feature test asserts on `->viewData('page')['flash']` rather than through `AssertableInertia`. That placement is the point: flash data never enters the browser's history state, so a back navigation cannot replay an old toast.
2. **`Inertia::flash()` stores through `session()->now()`**, which lands the key in `_flash.old`. `ageFlashData()` forgets old keys when the session saves, so a `now()` payload does not survive the redirect an action performs - the middleware's re-flash cannot save it. Since every manage mutation redirects, `Toast` flashes *forward* into the same session key instead. Revisit if the package starts flashing forward itself.
3. **The Vue adapter has no `Flash` component or `useFlash` composable at 2.1.3** (`@inertiajs/vue3` exports `router`, `usePage`, `Deferred`, `Form`, `Head`, `Link`, `useForm`, `usePoll`, `usePrefetch`, `useRemember`, `WhenVisible`). `useToasts` watches `page.flash.toast` itself; swap it for the adapter's own helper once the dependency is upgraded.

Bulk actions POST an `ids[]` array. Guarded bulk deletes keep today's all-or-nothing semantics: if any selected record fails the policy, nothing is deleted and a danger toast explains why.

### 2.6 Forms

Server-declared sections, client-rendered fields; validation via Form Requests so the same rules serve both panels during the parallel phase.

- Live slugging (`title` -> `slug`, create-only for Shows and Recordings, always for Sources and Roles) is a client watcher plus a `unique` rule server-side.
- Shows: `scheduled_end` must be `after:scheduled_start`; `status` select disabled while live; the Statistics section only renders on edit.
- Recordings: selecting a show prefills title, description, date and duration. Ship the candidate shows as a prop with `actual_start`/`actual_end` so the prefill happens client-side without an extra request.
- Servers: `hetzner_id`, `shared_secret` and `type` are disabled on edit; `max_clients` and `immutable` only render for edge.
- Emotes: `name` regex `^[a-z0-9_]+$`, max 20, unique; the approver/timestamp stamping stays server-side in the controller (mirroring `CreateEmote`/`EditEmote`).
- Branding: same four sections, helper text still sourced from `BrandingService::EDITABLE`, plus the reset-to-defaults confirm.

Timezone: every datetime field is `Europe/Berlin` with seconds off, as today. Format on the server, send ISO strings plus a preformatted display string, and let `dayjs` handle only the display of relative times.

### 2.7 Uploads

`POST /manage/uploads` accepts one file plus a `purpose` (`show_thumbnail`, `recording_thumbnail`, `emote`, `branding_logo`, `branding_login_image`, `branding_login_video`). Purpose determines disk, directory, visibility, accepted mime types, max size and any resize, reproducing the per-field config from the audit. Returns a redirect back with the stored path flashed, which the form field then submits as a normal field value.

Reads keep using the existing signed-URL accessors (`Show::thumbnail_url`, `Emote::url`, `Recording::thumbnail_url`) because the S3 objects are private. Branding uploads stay on the public disk.

Emote 1:1 crop and the 64x64 / 1280x720 resizes happen server-side on upload; the client only previews.

### 2.8 Navigation and badges

One `ManageNavigation` service returns the rail structure with live badge counts (live/upcoming shows, online sources, pending emotes, role count) so the counts are computed in one place and shared as a prop on every `/manage` response. Badge queries are cheap counts; cache for 5s if the poll makes them hot.

`RecordingResource`'s undeclared `Content` group becomes a real group in the structure.

### 2.9 Deliberate behaviour changes

Not parity gaps - decisions. Each needs a line in the cutover PR description.

1. `/admin/login` disappears; authentication is OIDC only.
2. `ViewCountChart`'s hardcoded September-2023 dates are replaced by a selectable range (default: last 7 days, plus a per-show mode on the statistics page). Porting the hardcoded dates would ship a known-broken widget.
3. `ServerResource`'s unregistered `UserRelationManager` is not ported (dead code). Instead, the server detail page gets an "Assigned users" tab, which is what it was clearly for.
4. The Users module gains a real messages tab (read-only list with a delete action gated on `chat.moderate`); today's relation manager is unreachable.
5. Filament's global search (only `User.name`) becomes a rail search across shows, sources, servers and users - cheaper to do well than to deliberately restrict.
6. `ViewInstallScript`'s regex-extraction of config blocks out of a generated shell script is replaced by asking `ServerProvisioningService` for each config directly. The FFmpeg placeholder tabs are dropped rather than shipped as `# not available in current implementation`.
7. Server mutations require `stream.manage` (or admin). The Filament panel let anyone holding only `filament.access` - a chat moderator, say - edit, delete and deprovision infrastructure, because panel access was the only check. Reading the list is still open to every `access-manage` holder. `ServerPolicy` is the single place this lives.
8. **Autoscaling is removed, not ported.** The feature is gone from the product: capacity is provisioned by hand behind an nginx reverse proxy. Deleted with it: `AutoscalerService`, `AutoscalerAction`, `ScalingJob` and its every-minute schedule entry, the `stream.autoscale` config block, both Filament header actions, and the status-strip segment. The `servers.immutable` column only ever protected a server from autoscaler deletion, so the panel no longer offers it (the column stays; dropping it needs a migration and buys nothing). `StreamScalingListener` is *not* autoscaling and stays: it is what Stream Control's "start servers" and "delete servers" actions drive.
9. **No dashboard.** The status strip carries the numbers a dashboard would have shown, so `/manage` redirects to the first module. `Overview::edgeServerCards()` and `capacityCards()` are kept for the Stream Control page (phase 6); the view-count chart is deferred with the dashboard rather than ported with its hardcoded 2023 dates.
10. **Shows lost tags, metadata and server pinning**, at the operator's request. All three columns stay in the database; nothing in the panel reads or writes them. A live show's slug is also frozen server-side, not just disabled in the form: it is the URL people are watching.
11. **`ShowStatisticsService::getHourlyStats()` was MySQL-only.** `DATE_FORMAT` does not exist on Postgres or SQLite, so the statistics page 500'd everywhere except production. Bucketing now happens in PHP. This fixed the Filament page too.
12. **`Source::getRtmpServerUrl()` dereferenced null.** With no active origin server it read `->hostname` off `null` and took the page down - at exactly the moment an operator needs it. It returns null now and the field renders "No origin server is active".
13. The servers list hides deleted servers as a *filter default* rather than a hard query scope. In Filament the scope always applied, which made the "Deleted" option in the status filter dead - picking it could only return an empty table. Selecting it now works.

---

## Part 3 - Build order

Each phase is one PR, ends green, and leaves `/admin` fully working.

**Phase 0 - foundations. DONE.** Tokens in `app.css`; `ManageLayout` + rail + status strip + toast host; `routes/manage.php` with only the dashboard; `access-manage` gate; the `Table` builder and `DataTable`/`FilterBar`/`Pagination`/`StatusBadge`/`ActionButton`/`ConfirmDialog` components; upload endpoint; `tests/Feature/Manage/AccessTest.php`. Dashboard shows the three widget equivalents (`ServerActive`, `Capacity`, view-count chart with a dynamic range).

**Phase 1 - Servers. DONE.** All ten columns, both filters, deleted hidden by default; provision-cloud-server modal with the single-origin guard; autoscaler enable/disable; deprovision vs delete split by `hetzner_id`; install-script page with tabs, copy and download; assigned users on the detail page. `ServerPolicy` plus `tests/Feature/Manage/ServersTest.php` (38 cases, 301 assertions). Built along the way: `ServerFactory`, and the `FormSection` / `FormField` / `FormActions` / `CodeBlock` components the remaining modules reuse.

**Phase 2 - Sources. DONE.** List (10s poll), status update single + bulk, guarded deletes, stream-key regeneration, OBS URL/key copy blocks via `CopyableText`, shows tab. `SourcePolicy` plus `tests/Feature/Manage/SourcesTest.php` (25 cases).

**Phase 3 - Shows. DONE** (minus the deliberate cuts in 2.9). Biggest module. Five form sections, thumbnail upload, tags, role restriction, metadata; list with 12 columns, 5 filters (`hide_ended` default on), 5s poll; go-live / end / cancel-bulk / guarded delete; capture-screenshot with its disabled reasons; statistics page from `ShowStatisticsService` including realtime stats while live; viewers tab.

**Phase 4 - Roles and Users.** Role form (4 sections, colour picker, permission tags, metadata), 3 ternary filters, inline `is_staff` / `is_visible` toggles, create-default-roles seeding action, users tab with pivot `expires_at` + `assigned_by` on attach. Users list, edit, roles tab, messages tab.

**Phase 5 - Emotes and Recordings.** Emote approve/reject single + bulk with the approver stamping, pending badge; recording form with show prefill, thumbnail regeneration single + bulk dispatching `ProcessRecordingJob`.

**Phase 6 - Stream Control and Branding.** Four stream-status actions with their exact confirm copy and tooltips; branding form with save + reset.

**Phase 7 - parity gate and cutover.** See Part 5.

---

## Part 4 - Test plan

### 4.1 Server-side parity tests

`tests/Feature/Manage/{Module}Test.php`, one per module, plus `AccessTest` and `NavigationTest`. Each module test covers:

1. **Access** - guest redirects to `/login`; a signed-in user without the gate gets 403; a staff user gets 200.
2. **Index contract** - asserts the Inertia component name and that `columns` contains every key from the audit doc, in order:
   ```php
   $this->actingAs($this->admin)->get(route('manage.shows.index'))
       ->assertInertia(fn (Assert $page) => $page
           ->component('Manage/Shows/Index')
           ->where('columns.*.key', [
               'thumbnail','title','source','status','scheduled_start','actual_start',
               'viewer_count','peak_viewer_count','auto_mode','required_roles','tags',
           ])
           ->where('filters.1.key', 'status')
           ->where('filters.0.value', true)   // hide_ended defaults on
       );
   ```
   This is the mechanism that makes "feature parity" checkable rather than asserted by hand: the expected column and filter lists are transcribed from the audit doc, so a dropped column fails a test.
3. **Filters, sort, search, pagination** - one case per filter proving it changes the row set (including that `hide_ended` is applied without a query string), default sort direction, and that search matches the same fields Filament marked `searchable()`.
4. **Every action** - happy path (state changed, correct toast flashed, correct redirect) plus authorization (403 without the permission) plus each guard:
   - deleting a live show is blocked and flashes a danger toast
   - deleting a source with live shows is blocked
   - deleting a role with users is blocked
   - provisioning a second origin server is refused while one is active or provisioning
   - screenshot capture is offered as disabled with the right reason when the show is not live or has no source
   - bulk delete is all-or-nothing when one record fails the guard
5. **Forms** - validation rules (required, unique slug, `scheduled_end` after start, emote name regex, URL fields), create and update writing the expected columns, and the server-side stamping (emote approver + `approved_at`, role-user `assigned_at`).
6. **Uploads** - `Storage::fake('s3')`, one case per purpose asserting disk, directory, visibility and that the model stores the path while the accessor returns a signed URL.

Reuse the existing setup from `tests/Feature/Filament/AdminPanelTest.php` (admin role with `admin.access` + `filament.access`, plus a plain user) - lift it into a `Tests\Concerns\CreatesManageUsers` trait so both suites share it during the parallel phase.

Rough size: ~25-40 assertions per module, ~220 total. Runtime target under 20s on the existing Postgres setup.

### 4.2 Playwright smoke set

Four to six specs only, covering what feature tests structurally cannot:

1. **Poll** - shows index; change `viewer_count` in the DB; assert the cell updates without a full navigation and that scroll position and column-toggle state survive.
2. **Upload** - attach a PNG to a show thumbnail, save, assert the preview and the table image render.
3. **Confirm dialog** - go-live on a scheduled show: dialog copy matches, cancel does nothing, confirm flips the badge to LIVE and raises a toast.
4. **Inline toggle** - flip a role's `is_staff` from the table and assert it persisted after a reload.
5. **Install script** - open the tabs, copy, download; assert the downloaded filename.
6. **Rail + status strip** - collapse/expand persists; a status-strip segment deep-links to the right module.

`@playwright/test` as a dev dependency, one CI job, `php artisan serve --env=testing` against a seeded database. Keep it out of the default `php artisan test` path so the fast suite stays fast.

### 4.3 The parity gate

Cutover requires all of:

- [ ] Every row in `current-filament-features.md` sections 2-4 maps to either a passing test or an entry in section 2.9 of this file.
- [ ] `php artisan test` green, including the legacy Filament suite (fix the one stale assertion in `AdminPanelTest` rather than deleting the test, so the old panel stays honest until it is removed).
- [ ] Playwright smoke green.
- [ ] `./vendor/bin/pint` clean.
- [ ] A manual walkthrough by an operator against a seeded database, exercising one live event end to end: source online -> show go-live -> viewers appear -> screenshot -> end -> statistics.

A short checklist file (`docs/admin/parity-checklist.md`, generated by transcribing the audit doc's tables) is the artefact reviewers tick through in the cutover PR.

---

## Part 5 - Cutover and Filament removal

Phase 7, one PR, only once the gate above is fully green.

1. `Route::redirect('/admin/{path?}', '/manage', 301)` with a `where('path', '.*')`, replacing the panel route registration.
2. Delete `app/Providers/Filament/AdminPanelProvider.php` and remove it from the providers array (`config/app.php:171`; there is no `bootstrap/providers.php` in this app).
3. Delete `app/Filament/` entirely (41 files, ~3 500 lines).
4. Delete `resources/views/filament/`. Keep `resources/views/server-provisioning/**` and the `Caddyfile`/`docker-compose`/`*-conf` blades - those are provisioning templates used by `ServerProvisioningService`, not admin views.
5. `composer remove filament/filament filament/upgrade` and drop `@php artisan filament:upgrade` from the `composer.json` scripts block.
6. Drop the transitive packages. Verified by grep: Livewire is referenced only in `config/livewire.php` (published config) and one option in `config/sentry.php`, never in `app/` outside `app/Filament`, so `livewire/livewire` goes with Filament - delete `config/livewire.php` and the Sentry option. `Flowframe\Trend` is used only by `app/Filament/Widgets/ViewCountChart.php`; either keep it for the new chart's hourly aggregation or replace it with a plain grouped query and remove `flowframe/laravel-trend`.
7. Delete `tests/Feature/Filament/AdminPanelTest.php` in this same PR, after confirming its 17 cases are all covered by `tests/Feature/Manage/*`.
8. Remove the `auth.can_access_filament` alias from `HandleInertiaRequests` and update any frontend reference to it.
9. Rename `filament.access` -> keep as-is. It is stored in `roles.permissions` rows in production; renaming needs a data migration and buys nothing. Document that the string is historical.
10. Update `CLAUDE.md`: drop "Admin Panel: Filament 3" from the tech stack, describe `/manage`, and note that `/admin` is a redirect.

Rollback during the parallel phase is trivial (nothing was removed). After phase 7 it is a revert of that single PR, so keep it mechanical: no behaviour changes in the removal commit.

### Risks

| Risk | Mitigation |
|---|---|
| Cutover lands near a live event | Freeze phase 7 during event windows; the parallel panel means there is never pressure to ship it |
| A column, filter or guard silently dropped | The column/filter list assertions in 4.1 are transcribed from the audit doc, so drops fail tests |
| Private-S3 upload/signed-URL regressions | `Storage::fake('s3')` per purpose plus the Playwright upload spec |
| Polling load multiplies (7 tables x N operators) | `only:` partial reloads, pause on hidden tab / dirty form, Echo-driven refresh where a channel already exists, cached badge counts |
| Column-toggle and rail state lost on every navigation | Session-persisted toggles, `localStorage` rail state, asserted in the poll smoke spec |
| Livewire removal breaks something unrelated | Grep for Livewire usage outside `app/Filament` before removing; if anything turns up, keep the package |
