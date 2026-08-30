# Release checklist

The order to deploy a release in, and what breaks if it is done in another one.

This release moves configuration out of `.env` into the settings table, replaces the
single identity provider with a provider table, and replaces every streaming server's
credential. Written for when you are ready to cut the tag, which is not the same day as
finishing the work: the environment import in step 3 and the verification in step 4 are
what decide whether releasing is safe.

Read the
[moving an existing `.env` into the table](settings.md#moving-an-existing-env-into-the-table)
section of the settings doc before starting. This page says when to do things, not how.

## The one thing that can go badly wrong

Nearly all of this release's risk is in a single migration, and it is the sign-in one.

`2026_08_30_100200_seed_legacy_auth_provider` reads `OIDC_URL`, `OIDC_CLIENT_ID` and
`OIDC_SECRET` out of the environment exactly once and copies them into the new
`auth_providers` table, then backfills a `user_identities` row for every account that has
a subject. If it cannot see those three variables, the provider row is seeded with no
endpoint behind it and comes out disabled. Password sign-in ships off, so the result is an
installation nobody can sign in to, including the account that would fix it.

The deploy runs `php artisan migrate --seed` automatically inside the ArgoCD sync. There is
no window between the migration and the new pods coming up, and nothing to do by hand at
the right moment. The check has to happen before you patch the tag.

The server credential change is the loud one in the diff but not the dangerous one here.
See step 5.

## 1. Pre-deploy

- **Confirm the server fleet really is empty.** The rest of this page is written on the
  assumption that it is, and the risk profile changes if it is not.
  - /manage > Servers, with the **Status filter cleared**. This matters: the list defaults
    to hiding `deleted` rows, so an empty-looking list is not by itself proof the table is
    empty. Clearing the filter shows every row including deleted ones.
  - /manage dashboard shows zero edge capacity. Note that **there is no alert for having
    no servers** - the alert list is only populated by rows that exist, so a clean
    dashboard is consistent with an empty fleet and tells you nothing on its own. You have
    to look at the list.
  - **The Hetzner console is the authoritative check for machines that are actually
    billing.** A row marked `deleted` means the application believes the machine is gone,
    which is not the same as it being gone: if a teardown failed after marking the row, the
    VM outlives the record and nothing in the panel will ever mention it again.
  - If the fleet is not empty, stop and read step 5 properly, then pick a window with
    nothing on air and nothing scheduled for two hours.
- **Check `OIDC_URL`, `OIDC_CLIENT_ID` and `OIDC_SECRET` are in the environment the migrate
  job runs with.** See above for why. Do not prune `.env` or the secret before the deploy.
  Prune after step 3.
- **Take a database dump and confirm it restores.** `mysqldump` of the whole schema. This
  is the only copy of two things the migrations destroy: the five settings rows listed in
  step 6, and the per-show recording detail listed there too.
- **Record the tag you are on**, which is the rollback point:
  ```bash
  kubectl -n argocd get application streaming-prod \
    -o jsonpath='{.spec.source.helm.valuesObject.image.tag}'
  ```
- **Check `DNS_DRIVER` and `DNS_ZONE`** if this installation uses managed DNS. A migration
  stamps the current driver and zone onto every server that has a hostname, so a later
  teardown asks the right API about the right record. With no servers it stamps nothing and
  the question is moot; with `DNS_DRIVER` unset it also deliberately stamps nothing, and
  every row falls back to the configured driver at read time, which is the same answer.

## 2. Deploy

```bash
gh release create vX.Y.Z --target main
kubectl -n argocd patch application streaming-prod --type=merge \
  -p '{"spec":{"source":{"helm":{"valuesObject":{"image":{"tag":"X.Y.Z"}}}}}}'
```

A plain push to `main` builds the `edge` tag and deploys nothing. `streaming-prod` has
`syncPolicy.automated`, so the patch syncs within a couple of minutes and rolls the app,
Horizon, Reverb and scheduler deployments.

**Migrations run inside the sync**, in the chart's migrate job, with `seed: true`. On
production `--seed` runs `RoleSeeder` only, which is `firstOrCreate` per role and leaves
existing rows alone; the rest of the seeders are behind an `App::isLocal()` guard.

With an empty fleet the rolling window is uneventful. Old and new pods serve traffic side
by side against the new schema for a minute or two, and the only thing old pods do that the
new schema refuses is look a server up by `servers.shared_secret`, which is the SRS publish
hook and the server API. With no servers, nothing calls either.

## 3. Import the environment into the settings table

As soon as the sync is green, in an app pod:

```bash
kubectl -n <namespace> exec deploy/streaming-app -- php artisan settings:import-env
kubectl -n <namespace> exec deploy/streaming-app -- php artisan settings:import-env --write
kubectl -n <namespace> exec deploy/streaming-app -- php artisan branding:set --list
```

A dry run is the default and the first command changes nothing. It must end up with one
settings row per field the environment is still supplying, having refused to overwrite any
row somebody saved in the panel, and it must exit zero. A non-zero exit means a field in
`config/settings.php` has no classification in `App\Support\EnvImport` and the command is
telling you it cannot account for the whole registry; do not prune `.env` until it does.

It will not touch `OIDC_*` or `COMPANION_API_KEY`. Those moved into tables of their own and
the migrations in step 2 already copied them. It reports which migration did.

Only after this has written and been read back is it safe to delete the variables it names
as redundant. Some stay whatever it says, because something outside the application reads
them too; the settings doc lists which.

## 4. Verify, in this order

Five minutes, highest-consequence first.

1. **Sign in.** Private window, sign in through the identity provider. First because the
   provider migration is the one thing in this release that can lock every account out at
   once, including the account that would fix it.
   - There is a provider button on the sign-in screen at all. No button means the row was
     seeded disabled. See below.
   - You land back signed in, with your name and avatar.
   - Roles survived: an account that had staff or sponsor still has its badge. The mapping
     was rebuilt from `roles.external_id` into the provider's `role_map`, visible at
     /manage > Settings > Sign-in.
2. **Playback.** With no servers there is nothing to put on air, so this is the archive
   only: open a recording from /archive, play it, scrub it. Skip markers appear where they
   had them. Live playback gets its real test when the first server is provisioned, which
   is step 5.
3. **The panel.**
   - /manage dashboard loads, capacity reads zero, and **the alert list is clean**. If a
     server alert appears, the fleet was not empty and you are now in step 5 for real.
   - /manage > Servers is still empty, filter cleared.
   - /manage > Recordings > Plan loads. The Recording column reads `ok` or `lost` for the
     stream capture and nothing else; the old `no_audio`, `no_video` and `incomplete`
     answers were folded into `lost` on the way through.
   - /manage > Settings opens each pane without error, and the values match what the import
     reported in step 3.
   - /manage > Comments, Feedback and Telegram load.

### If sign-in is dead

The provider row exists but is switched off, and there is no way in through the browser.
From a pod:

```bash
kubectl -n <namespace> exec deploy/streaming-app -- php artisan tinker --execute="
  \$p = App\Models\AuthProvider::where('key','identity')->first();
  dump(\$p->only(['key','client_id','issuer_url','enabled']));"
```

Empty `client_id` or `issuer_url` means the migrate job did not see `OIDC_*`. Put them back
into the environment, fill the row in with `branding:set` or tinker, and set `enabled` to
true. If they are populated and only `enabled` is false, set it to true.

## 5. Servers, from now on

The credential a server presents is hashed now, is no longer accepted in a query string,
and is no longer what the app looks the server up *by*. The migration drops the old
plaintext `servers.shared_secret` column rather than carrying the values over, because
those values are in access logs and in cloud-init user-data.

**With no fleet running, this costs nothing on deploy day.** There is nothing to rotate,
no origin to restart, no ingest to interrupt and no viewer to disturb. The three server
migrations run against an empty table and do nothing: the credential swap, the
`provider`/`external_id` backfill and the DNS stamping all touch zero rows. Verified by
migrating a database with no servers in it.

What it means going forward:

- **A server provisioned from now on is fine automatically.** Credentials are minted by the
  model, stored hashed, and handed out once through the install script.
- **Download the install script in the same session that mints it.** Only hashes are
  stored, so the plaintext exists for exactly as long as that browser session. Come back
  tomorrow and the page offers a rotate, not a script.
- **If you ever meet a server that predates this release** - one restored from a snapshot,
  or a box someone kept warm - it will report **Credentials rejected** on its server page
  and raise a danger alert on the dashboard. Nothing it is serving stops: edges verify
  playback tokens locally, so viewers keep watching and an origin keeps ingesting and
  recording. What stops is its half of the conversation with the app: heartbeats, metrics,
  config fetches, registration.

The per-server recovery, the order to do boxes in, and why edges go one at a time and the
origin goes between shows are in
[Server credentials](server-credentials.md#what-to-do-per-server). Follow it there.

## 6. Rollback

Rolling the image back is one command. Rolling the database back is not the same thing as
undoing the release, and for three of these migrations it is not possible at all.

**The image:**

```bash
kubectl -n argocd patch application streaming-prod --type=merge \
  -p '{"spec":{"source":{"helm":{"valuesObject":{"image":{"tag":"<previous>"}}}}}}'
```

**What a `migrate:rollback` gets you.** All twenty-four migrations ran in one batch, so a
single `migrate:rollback` reverses the lot, and it does run cleanly on MySQL 8. What comes
back is the old shape with holes in it:

- **The five deleted settings rows are gone.** `rtmp_forward_url`,
  `rtmp_forward_vrchat_url`, `local_streaming_ipv4_subnet`, `local_streaming_ipv6_subnet`
  and `local_streaming_hostname` are deleted outright, and the down migration deliberately
  does nothing, because the settings they belonged to no longer exist. The `oidc_*` and
  `auth_oidc` rows are deleted too, after being copied into the provider table. Restoring
  any of them means the dump from step 1.
- **Recording plan detail is gone.** `stream_condition` collapsed `no_audio`, `no_video`
  and `incomplete` into `lost` and there is nothing to expand them back out of.
  `archive_pgm_at` and `archive_iso_at` come back as empty columns. `onsite_status`
  reconstructs approximately: anything short of lost reads back as a usable master, and a
  row that was `expected` comes back NULL.
- **Server credentials do not come back.** `servers.shared_secret` returns as a column with
  NULL in every row, and old code looks a server up by that value. Academic while the fleet
  is empty, and the reason it stays on this list is that it stops being academic the moment
  one is provisioned: after that, rolling back means reinstalling every box either way.
- **`users.sub` stays nullable.** The down migration is a deliberate no-op, because putting
  the NOT NULL back would fail the moment one local account exists and there is no subject
  to invent for it. Harmless: old code never writes a null there.

**What does roll back cleanly:** sign-in. `user_identities` is emptied and the provider row
is deleted, but `users.sub` is untouched throughout, so the old sign-in path finds every
account exactly where it left it. Nobody loses an account by rolling back.

**The honest summary.** If the problem is the application, roll the image back and leave the
database alone; the new schema is a superset and the old code reads it. If the problem is
the data, restore the dump. Rolling the migrations back is the worst of the three, because
it destroys detail on the way out and does not restore what you actually lost.
