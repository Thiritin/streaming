# Settings

Most of what makes one installation different from another is edited at `/manage` >
**Settings** and stored in the database. There is no deploy, no rebuild and no container
restart between saving a value and it being in force.

`.env` is still there, and still read. It is the shipped fallback: a value in the
environment is what the installation answers with until somebody saves a row for that
field, and from then on the row wins. Nothing has two sources that can disagree.

## The panes

One pane per group, each with its own URL and its own entry in the menu down the left.

| Pane | What it holds |
|---|---|
| Identity | Convention name, site name, and what the identity provider is called on the sign-in screen |
| Sign-in | The four switches, and the provider's URL, client ID and secret. See [authentication.md](authentication.md) |
| Login screen | The copy on the sign-in page |
| Look | Logo, tab icon, accent colour, login background |
| Footer links | The links in the footer, and the source and licence credit |
| Announcement | The front-page banner and the page behind it |
| Features | Chat, emotes, boops, comments, announcements, feedback, screens, Telegram, notifications |
| Chat | Rate limits, slow mode, message length, which link domains stay clickable |
| Pretalx | Instance URL, event slug, API token. See [pretalx-import.md](pretalx-import.md) |
| Control surfaces | The control key. See [companion.md](companion.md) |
| Imports | The import key. See [archive-import.md](archive-import.md) |
| Archive storage | The archive bucket and its credentials, the disk, the quota, how segment URLs are handed to a player |
| Streaming | Container images for the generated provisioning scripts, SRS console credentials, RTMP forward targets, metrics retention, the venue network overrides |
| Playback security | The two token secrets, the token timings, the system streamkey, the recording API key |
| Telegram | Bot token, bot @name, how early a show is announced. See [telegram.md](telegram.md) |
| Notifications | How long a new recording is held before viewers are told |
| Events, Categories | Sets of rows rather than sets of knobs, so they join the same menu by hand |
| Reset | Deletes every saved row, leaving the shipped config |

Everything else about a convention - shows, sources, servers, recordings - is a record,
not a setting, and lives in its own module.

## How a saved value reaches the code

Saving writes one row keyed by the field's flat key. `App\Support\RuntimeConfig` then lays
those rows over the config repository, so a call site goes on reading
`config('services.oidc.url')` and never learns that an administrator changed it. Adding a
knob is one entry in `config/settings.php`, not a form, a request class and a page change.

The overlay is applied at boot, again on every Octane request, and again between queue
jobs, because both of those keep a booted container alive for hours. Between jobs and
never inside one: a long import or archive scan finishes on the values it started with,
and a change is a change for the next job.

Two consequences worth knowing before you go looking for a knob that appears to do
nothing:

- **A value read once at boot is fixed for the life of the process.** Anything captured
  into something built at startup - an HTTP macro, a bound singleton - keeps whatever it
  resolved. Making such a value settable means moving its read to call time first.
- **A resolved disk keeps the credentials it was built with.** `RuntimeConfig` forgets the
  disks it overrides, which is why changing the archive bucket takes effect in every
  worker rather than only in the one that handled the save.

`php artisan config:cache` and `php artisan optimize` write the config repository to
disk. Both are handed the shipped config with the overlay switched off, so a saved value -
and especially an encrypted one - is never baked into `bootstrap/cache/config.php`.

## Secrets

A field can be stored encrypted at rest. Two kinds, and the difference is who the secret
belongs to:

- **Write-only** - the identity provider's client secret, the pretalx token, the Telegram
  bot token, the archive bucket's key and secret, the SRS console password, and the two
  playback token secrets. These are values somebody else issued, or values the edges hold
  a copy of. The pane shows a mask, saving the mask leaves the stored value alone, and a
  Clear control beside it removes it. They are never read back out of the panel or out of
  `branding:set --list`.
- **Readable** - the control key, the import key, the system streamkey and the recording
  API key. These are values this installation hands out to somebody, so they can be read,
  copied and generated in place.

Encryption is against `APP_KEY`. Losing `APP_KEY` loses these rows, the same way it loses
every other encrypted thing in the application.

## Values that also live somewhere else

Some settings are not only read by the app. They are written into a file on a streaming
server when it is provisioned, and saving in the panel changes only the app's half. The
pane says so on the field, but the list is worth having in one place:

| Field | Also held by | What drift looks like |
|---|---|---|
| Archive bucket, endpoint, region, key, secret | The origin's uploader | Silent. Uploads keep succeeding into the old bucket and the archive just stops growing |
| Viewer token secret, embed token secret | Every edge | Immediate. Every viewer's playback 403s until each edge is reinstalled |
| System streamkey | Every edge | Thumbnails, the archive uploader and the operator preview stop working |
| Expiry leeway | Every edge | Intermittent 403s at token-refresh boundaries, which is harder to diagnose than a clean break |
| SRS console password | The origin, bcrypted into its generated config | The console stops accepting the password |
| Transcoder and uploader images | The generated compose file | The box keeps running the image it pulled |
| Recording API key | Whatever external tooling posts recordings | That tooling gets 401s |

Rotating any of the first four is an outage, not a save. Do it between shows, and see
[server-credentials.md](server-credentials.md) for how a server is reinstalled.

## From the command line

`branding:set` is the same write from a shell, for a deploy that wants to arrive already
configured. Despite the name it covers every field in every pane, not only branding.

```bash
php artisan branding:set --list
php artisan branding:set site_name="Example Con" primary_color="#7c5cff"
php artisan branding:set footer_links='[{"label":"Privacy","url":"https://example.org/privacy"}]'
```

`--list` prints each key with its current value and its shipped default. A field stored
encrypted prints as a mask: it is written from here, never read back out of it.

## Reset

The Reset pane deletes every saved row, which puts the installation back to whatever
`.env` and the config files say. It refuses when that would leave nobody able to sign in -
an installation whose provider details were typed into the panel rather than set in the
environment has nothing behind them once the rows go.

## Back to the defaults, one field at a time

Saving a field the value it already has as a shipped default deletes its row rather than
storing it. The field is then following config again, which is what "no override" means
and what the panel reports.
