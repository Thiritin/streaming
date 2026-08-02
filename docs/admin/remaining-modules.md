# Remaining modules, and switching Filament off

What is left between today's `/manage` and deleting `app/Filament`. Written to be built from directly: every module lists its routes, its prop contract, its actions with the copy they carry, and the tests that hold it to the audit.

Companions: [`current-filament-features.md`](./current-filament-features.md) is the parity contract, [`rebuild-plan.md`](./rebuild-plan.md) is the architecture and the list of deliberate changes, [`parity-checklist.md`](./parity-checklist.md) is the tick list for the cutover PR.

## Where things stand

| Module | State |
|---|---|
| Servers | Done — `ServersTest` |
| Sources | Done — `SourcesTest` |
| Shows (+ planner, statistics) | Done — `ShowsTest`, `ShowPlannerTest` |
| Branding | Done, as config-driven `Manage/Settings` |
| Dashboard | Done (replaces the Capacity / ServerActive widgets) |
| **Roles** | Missing |
| **Users** | Missing |
| **Emotes** | Missing |
| **Recordings** | Missing |
| **Stream Control** | Missing |

`Navigation.php` already advertises all five. `item()` drops any route that does not exist, so the sidebar grows as each lands and nothing 404s in the meantime.

## Build order

Roles first: Users' role tab and Shows' access restriction both read role data, and the pivot UI is shared.

1. **Roles** — pivot UI, inline toggles, the default-roles action
2. **Users** — reuses the pivot panel from Roles
3. **Emotes** — small, self-contained
4. **Recordings** — medium, plus the new thumbnail/publish flow
5. **Stream Control** — small, but the confirm copy must be exact
6. **Remove Filament**

---

## 1. Roles

`RoleResource` + its Users relation manager. Audit §2.5.

> ### The Filament Roles UI is partly fiction
>
> Two migrations on 2025-08-29 removed columns the resource still renders:
>
> - `2025_08_29_171750_remove_is_staff_from_roles_table` dropped `roles.is_staff`
> - `2025_08_29_170705_simplify_role_user_table` dropped `role_user.assigned_at`,
>   `role_user.expires_at` and `role_user.assigned_by`, leaving `assigned_by_user_id`
>   plus timestamps
>
> `RoleResource` was never updated. So today its `is_staff` toggle writes a field that is
> not fillable and does not exist, its `is_staff` **inline toggle column** would fail on
> write, and its Users relation manager's `pivot.assigned_at` / `pivot.expires_at` /
> `pivot.assigned_by` columns, its Active/Expired filters and its attach form all reference
> dropped columns. `createDefaultRoles()` passes `is_staff` too; it is silently discarded.
>
> **Build to the schema, not to the resource.** Role expiry is not a feature anyone has -
> it was deliberately removed - and reinstating it from a stale form would be inventing
> requirements. Anything below that the audit lists but the schema does not support is
> marked `DROPPED`, and the parity checklist entries for them are struck rather than ticked.
>
> `User::isStaff()` is `hasRole('admin')` and is unaffected.

### Routes

```
GET    /manage/roles                     manage.roles.index
GET    /manage/roles/create              manage.roles.create
POST   /manage/roles                     manage.roles.store
GET    /manage/roles/{role}              manage.roles.edit
PUT    /manage/roles/{role}              manage.roles.update
DELETE /manage/roles/{role}              manage.roles.destroy
POST   /manage/roles/{role}/toggle       manage.roles.toggle        (is_visible)
POST   /manage/roles/{role}/users        manage.roles.users.attach
DELETE /manage/roles/{role}/users/{user} manage.roles.users.detach
POST   /manage/roles/defaults            manage.roles.defaults
```

### List

Columns, in order: `name`, `slug`, `chat_color` (colour swatch, copyable), `priority` (tiered badge: ≥100 danger, ≥90 warn, ≥50 info, else idle), `login_sync` (Auto-synced / Manual badge), `is_visible` (**toggle column**, label "Chat badge"), `users_count`, `created_at` (hidden by default). Default sort `priority` desc.

`DROPPED`: the `is_staff` column and its toggle — no such database column.

Filters: two ternaries — `assigned_at_login` and `is_visible` — with their labels from the audit. `DROPPED`: the `is_staff` filter.

The toggle column writes immediately via `manage.roles.toggle` with `field=is_visible`; anything else is a 422. `DataTable` already renders `Column::toggle()`; the cell value is `['value' => bool, 'url' => string]`.

### Form

Sections, one column of `label: control` rows:

1. **Role information** — `name` (live-slug on create only), `slug` (unique), `description`
2. **Chat appearance** — `chat_color` (colour picker, default `#808080`), `priority`, `is_visible`
3. **Settings** — `assigned_at_login`, `permissions` (tags with the 8 suggestions: `filament.access`, `admin.access`, `chat.moderate`, `chat.delete`, `chat.timeout`, `chat.slowmode`, `stream.manage`, `user.manage`). `DROPPED`: `is_staff`.
4. **Users** (edit only) — the pivot panel below

`permissions` needs a tags input. `TagsInput.vue` was deleted when Shows dropped tags; restore it from git history (`git log --diff-filter=D -- resources/js/Components/Manage/TagsInput.vue`) rather than rewriting it.

`ColorPicker.vue` is new: a native `<input type="color">` beside a mono hex field, both bound to the same value, so a hex can be pasted as well as picked.

### Users pivot panel

New shared component `RelationPanel.vue`, used here and by Users. Table plus an attach form, no page navigation.

Columns: `name`, `email`, `created_at` on the pivot as "Assigned", and who assigned it, resolved from `assigned_by_user_id` (falling back to "System" when null).

`DROPPED`: the expiry column, the Active/Expired filters, and the `assigned_by` string badge — all reference dropped columns.

Attach form: user select (searchable) only. The pivot records `assigned_by_user_id = auth()->id()` and its own timestamps. Toast "User assigned" / "The user has been assigned to this role."

Detach: confirm, toast "User removed".

### Actions

- **Create Default Roles** — only when `Role::count() === 0`. Confirm heading "Create Default Roles", description "This will create the default set of roles for the system.", submit "Create Roles". Seeds Admin / Moderator / Super Sponsor / Sponsor / Attendee from `RoleResource::createDefaultRoles()` — colours, priorities, `assigned_at_login`, `is_visible` and permission sets, minus the `is_staff` key the database discards. Copy the array into `App\Support\Manage\DefaultRoles` before deleting the resource, and assert every surviving field of all five. Cross-check it against `RoleSeeder`, which already seeds roles and may be the better home.
- **Delete** — blocked while the role has users. Toast: "Cannot delete role" / "This role has assigned users. Remove all users before deleting." Same guard on bulk.

### Policy

`RolePolicy`: read for any `access-manage` holder; mutations need `user.manage` (or admin), matching the tightening applied to Servers and Shows. `delete()` returns false while `users()->exists()`.

### Tests — `tests/Feature/Manage/RolesTest.php`

Column and filter lists as corrected above; the priority ramp at each boundary (100, 90, 50, 0); the `is_visible` toggle persisting and an unknown field returning 422; delete blocked with users and allowed without; default-roles action hidden when roles exist and each of the five seeded correctly; attach stamping `assigned_by_user_id`; detach; `user.manage` required for every mutation.

Add one regression test asserting `roles` has no `is_staff` column and `role_user` no `expires_at`, so nobody rebuilds the fiction from the old resource after it is deleted.

---

## 2. Users

`UserResource` + Roles and Messages relation managers. Audit §2.4.

### Routes

```
GET    /manage/users                        manage.users.index
GET    /manage/users/{user}                 manage.users.edit
PUT    /manage/users/{user}                 manage.users.update
DELETE /manage/users/{user}                 manage.users.destroy
POST   /manage/users/{user}/roles           manage.users.roles.attach
DELETE /manage/users/{user}/roles/{role}    manage.users.roles.detach
DELETE /manage/users/{user}/messages/{message}  manage.users.messages.destroy
```

No create route. Users arrive through OIDC; the Filament create form could only produce a broken row (`sub` is disabled and required). **CHANGED** — record it in rebuild-plan 2.9.

### List

Columns: `sub` (mono, copyable), `name` (searchable, sortable), `reg_id`, `roles` (badge list), `server` (hostname or "unassigned"). Search over `name` and `sub`.

Filters: `has_server` ternary, `role` select (by slug).

### Form

Read-only: `sub`, `name`, `reg_id`, timestamps. Editable: `server_id`, limited to active edge servers, with an "Unassigned" option.

Tabs on the detail page:
- **Roles** — `RelationPanel`, attach by role select, detach with confirm. Columns: name, slug, chat colour, priority, login-sync (read-only toggle), assigned at. Sort priority desc. Same pivot caveat as Roles: there is no expiry.
- **Messages** — read-only list (message, `is_command`, sent at) with a delete action gated on `chat.moderate`. **CHANGED**: unreachable in Filament today.

### Policy

`UserPolicy`: read for any `access-manage` holder; `update`/`delete` and role attach/detach need `user.manage`; message delete needs `chat.moderate`.

### Tests — `tests/Feature/Manage/UsersTest.php`

No create route exists; server select offers only active edge servers; assigning and clearing a server; role attach/detach; message delete permission split (a moderator may delete a message but not change a role); search over both fields.

---

## 3. Emotes

`EmoteResource`. Audit §2.6.

### Routes

```
GET    /manage/emotes                  manage.emotes.index
GET    /manage/emotes/create           manage.emotes.create
POST   /manage/emotes                  manage.emotes.store
GET    /manage/emotes/{emote}          manage.emotes.edit
PUT    /manage/emotes/{emote}          manage.emotes.update
DELETE /manage/emotes/{emote}          manage.emotes.destroy
POST   /manage/emotes/{emote}/approve  manage.emotes.approve
POST   /manage/emotes/{emote}/reject   manage.emotes.reject
POST   /manage/emotes/bulk/approve     manage.emotes.bulk.approve
POST   /manage/emotes/bulk/reject      manage.emotes.bulk.reject
```

### List

Columns: `url` (image, 40px), `name` (rendered `:name:`, copyable, searchable), `uploadedBy.name`, `is_global` (bool), `is_approved` (bool, ok/warn), `usage_count`, `created_at`. Default sort `created_at` desc.

Filters: approval status (Pending / Approved), `is_global` ternary.

Nav badge: pending count, warn tone, hidden at zero. Already wired in `Navigation::badges()`.

### Form

`name` (required, unique, regex `^[a-z0-9_]+$`, max 20), image upload (purpose `emote`, already in `config/manage.php`: 1:1, 64×64, S3 private), `is_global`, `is_approved`. Read-only: uploader, approver, approved at, usage count.

Server-side stamping, exactly as the Filament page classes did: create sets `uploaded_by_user_id`, and if created pre-approved also `approved_by_user_id` + `approved_at`; update stamps both on the first transition to approved.

### Actions

- **Approve** — unapproved only, confirm, `$emote->approve(auth()->user())`
- **Reject** — unapproved only, confirm, description "This will permanently delete the emote and its image.", `$emote->reject()`
- Bulk approve / bulk reject, both skipping already-approved records
- Policy: `chat.moderate` (or admin) for approve/reject; `user.manage` not required — this is chat moderation, not user administration

### Tests — `tests/Feature/Manage/EmotesTest.php`

Name regex and length; approve stamps approver and timestamp; reject deletes the row and the S3 object (`Storage::fake`); bulk actions skip approved; a moderator *can* approve (the one place `chat.moderate` is enough); pending badge count.

---

## 4. Recordings

`RecordingResource`, plus the thumbnail/publish flow decided on 2026-07-30. Audit §2.7.

### Routes

```
GET    /manage/recordings                        manage.recordings.index
GET    /manage/recordings/create                 manage.recordings.create
POST   /manage/recordings                        manage.recordings.store
GET    /manage/recordings/{recording}            manage.recordings.edit
PUT    /manage/recordings/{recording}            manage.recordings.update
DELETE /manage/recordings/{recording}            manage.recordings.destroy
POST   /manage/recordings/{recording}/publish    manage.recordings.publish
POST   /manage/recordings/{recording}/unpublish  manage.recordings.unpublish
POST   /manage/recordings/{recording}/thumbnail  manage.recordings.thumbnail   (grab a frame)
POST   /manage/recordings/bulk/thumbnails        manage.recordings.bulk.thumbnails
```

### Its own thumbnail

The decision: **a recording's thumbnail is independent of the show's**. The show's is captured off the live stream and is read-only there; the recording's is set here, by upload (purpose `recording_thumbnail`) **or** by grabbing a frame at a timecode the operator picks.

`ProcessRecordingJob` already captures a frame; extend it to accept an optional `atSeconds` so "grab frame at 00:04:12" reuses the existing ffmpeg path rather than adding a second one. Default stays first-frame when no timecode is given.

Never copy `Show::$thumbnail_path` into a recording, and never write back — they are two different pictures of two different things.

### Draft / published

`is_published` already exists and already gates public visibility. Present it as an explicit state with two actions rather than a checkbox buried in the form:

- **Publish** — confirm, "It becomes visible in the public archive."
- **Unpublish** — confirm, "It disappears from the public archive. The file is kept."

New recordings are created as drafts regardless of what the form posts. **CHANGED** from Filament, where `is_published` defaulted to true.

### Cut markers

Show `actual_start` / `actual_end` from the linked show as read-only context with a link, so it is obvious where the in/out points came from. Editing them stays on the show.

### Form

`show_id` (searchable select, prefills title / description / date / duration from the show — ship candidates as a prop, no extra request), `title` (live-slug on create), `slug` (unique), `description`, `date`, `duration` (seconds, "auto-filled via ffmpeg if empty"), `m3u8_url` (required URL), thumbnail block (upload / grab-at-timecode / current preview), `required_roles` as the same **Public / Private** control Shows uses.

### List

Columns: thumbnail (80×45), title, slug (hidden), show badge, date, duration `H:MM:SS`, views, state badge (Draft / Published), access badge (hidden), created_at (hidden). Default sort `date` desc. Filters: state (draft/published), access.

### Tests — `tests/Feature/Manage/RecordingsTest.php`

Show prefill; slug uniqueness; created as draft; publish/unpublish round trip; thumbnail upload lands on the private disk and the accessor signs it; grab-at-timecode dispatches `ProcessRecordingJob` with the timecode; bulk regeneration counts only rows with an `m3u8_url`; a recording's thumbnail is untouched when the show's changes.

---

## 5. Stream Control

`Pages/Stream`. Audit §3.1.

### Routes

```
GET  /manage/stream         manage.stream
POST /manage/stream/status  manage.stream.status
```

### The four actions

Copy is verbatim from the audit — these are the buttons someone presses under pressure, so the tooltips matter:

| Action | Event value | Tone | Tooltip |
|---|---|---|---|
| Set Stream Starting Soon (Start Servers) | `STARTING_SOON` | warn | "Will start servers, takes around 6 minutes." |
| Set Stream Online | `ONLINE` | ok | "Set this after you started the stream in obs for the first time." |
| Set Stream Technical Issue | `TECHNICAL_ISSUE` | warn | "Set this if you have technical issues with the stream. Will automatically activate upon stream disconnect." |
| Set Stream Offline (Delete Servers) | `OFFLINE` | danger | "This sets the stream fully offline and deletes ALL Servers." |

Each fires `StreamStatusEvent`, each confirms first. The offline one deletes every server through `StreamScalingListener` — its confirm should say so in the body, not just the tooltip.

The page also shows the current status prominently (it is what the status strip reads) plus `Overview::edgeServerCards()` and `capacityCards()`, which have been waiting for exactly this page.

### Policy

`stream.manage` (or admin) for the endpoint. A read-only view for anyone else.

### Tests — `tests/Feature/Manage/StreamControlTest.php`

Each action dispatches its event with the right enum; an unknown status is a 422; a moderator gets the page but no actions and a 403 on POST; the cards report the same numbers as the status strip.

---

## 6. Removing Filament

Only once the parity checklist is fully ticked and `php artisan test` is green.

1. `Route::redirect('/admin/{path?}', '/manage', 301)->where('path', '.*')`.
2. Delete `app/Providers/Filament/AdminPanelProvider.php`; remove it from `config/app.php:171` (there is no `bootstrap/providers.php`).
3. Delete `app/Filament/` — 41 files.
4. Delete `resources/views/filament/`. **Keep** `resources/views/server-provisioning/**` and the `Caddyfile` / `docker-compose` / `*-conf` blades: those are provisioning templates the install-script page renders, not admin views.
5. `composer remove filament/filament filament/upgrade`; drop `@php artisan filament:upgrade` from the `composer.json` scripts block.
6. Drop the transitive packages. Verified by grep: Livewire appears only in `config/livewire.php` and one `config/sentry.php` option, never in `app/` outside `app/Filament` — so `livewire/livewire` goes too, along with that config file and option. `flowframe/laravel-trend` was only used by the deleted `ViewCountChart`.
7. Delete `tests/Feature/Filament/AdminPanelTest.php`, after confirming its 17 cases are all covered under `tests/Feature/Manage/`.
8. Remove the `auth.can_access_filament` alias from `HandleInertiaRequests` and update the public layout's admin link to `manage.home`.
9. Leave the `filament.access` permission string alone. It is stored in `roles.permissions` rows in production; renaming needs a data migration and buys nothing. The `access-manage` gate already accepts it. Note in the code that the name is historical.
10. Update `CLAUDE.md`: drop "Admin Panel: Filament 3", describe `/manage`, note `/admin` is a redirect.

Keep the removal commit mechanical — no behaviour changes in it — so it reverts cleanly if something surfaces.

### Before deleting, lift these out

Things that live only inside `app/Filament` and are still needed:

- `RoleResource::createDefaultRoles()` — the five default roles, exact payload
- The OBS helper text and the install-script tab labels, if any wording is still only there
- Anything in `resources/views/filament/resources/**` that is a real template rather than admin chrome (check before the `rm`)

## Concurrency note

A second agent is working in the same panel and has been editing `SourceController`, `Navigation.php`, `AccessTest`, the Dashboard and the Settings module. Before starting a module, check `git status` for its files. Roles, Users, Emotes, Recordings and Stream Control are untouched by that work, so they are safe to take.
