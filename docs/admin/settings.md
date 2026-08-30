# Settings

Most of what makes one installation different from another is edited at `/manage` >
**Settings** and stored in the database. There is no deploy, no rebuild and no container
restart between saving a value and it being in force.

`.env` is still there, and still read. It is the shipped fallback: a value in the
environment is what the installation answers with until somebody saves a row for that
field, and from then on the row wins. Nothing has two sources that can disagree.

## The panes

Twelve panes under three headings, plus Reset pinned under the menu. The heading says what
a pane is about, so the rows carry a name and nothing else.

### Site

| Pane | What it holds |
|---|---|
| Sign-in | Guest access, password accounts and registration, the sign-in providers, the provider's own pages, and the copy on the login screen. See [authentication.md](authentication.md) |
| Branding | The convention and site names, logo, tab icon, accent colour, login background, footer links |
| Announcement | The front-page banner and the page behind it |
| Features | Chat, emotes, boops, comments, announcements, feedback, screens, Telegram |
| Chat | Rate limits, slow mode, message length, which link domains stay clickable |

### Programme

| Pane | What it holds |
|---|---|
| Events | The convention's runs. Rows rather than knobs |
| Categories | What a show is. Rows rather than knobs |
| Pretalx | Instance URL, event slug, API token. See [pretalx-import.md](pretalx-import.md) |

### System

| Pane | What it holds |
|---|---|
| Streaming | Provisioning images and how long server metrics are kept |
| Archive storage | Which disk the archive lives on and how big it may get, the archive bucket's credentials, how segment URLs are handed to a player |
| Tokens and keys | The viewer and embed token secrets and their timings, and every key this installation issues: system streamkey, recording API key, control key ([companion.md](companion.md)), import key ([archive-import.md](archive-import.md)) |
| Notifications | The Telegram bot and how early shows are announced, and how long a new recording is held before viewers are told. See [telegram.md](telegram.md) |

**Reset** sits under the menu behind a divider rather than in it: it is one destructive
button, not a pane to browse. It keeps its URL.

Everything else about a convention - shows, sources, servers, recordings - is a record,
not a setting, and lives in its own module.

### Cards

A pane whose fields answer several different questions is split into cards - Sign-in is
five of them, Branding four, Tokens and keys four. A card is layout and nothing else: the
whole pane is still one form, saved by one button, validated as one set of rules. Cards do
not collapse; a card you have to open first is a click in front of every field it holds.

A card can go inert when another field on the pane has made its contents moot: it stays
on screen with its saved values readable and every control in it disabled, and one line
in place of its description says why. Archive storage is the one that does this today.
Inert is layout, like a hidden field: the values are still there and a save still carries
them, so switching back does not hand you an emptied card.

### Labels, not captions

Fields carry a label and a value. Where a helper only restated the label it is gone, and
where it carried a unit the label carries it instead - "Rate window (seconds)", "Quota
(bytes)". What is left is the handful that say something a label cannot: what switching a
feature off closes, what a Markdown field accepts, which header a key is sent in.

### Panes that used to exist

Nine panes became five. The old URLs redirect rather than 404, because they are printed in
these docs and pasted between operators:

| Old | Now |
|---|---|
| `/manage/settings/login` | Sign-in > Login screen |
| `/manage/settings/auth` | Sign-in |
| `/manage/settings/links` | Branding > Footer |
| `/manage/settings/look` | Branding |
| `/manage/settings/imports` | Tokens and keys > Imports |
| `/manage/settings/control` | Tokens and keys > Control surfaces |
| `/manage/settings/telegram` | Notifications > Telegram bot |

## How a saved value reaches the code

Saving writes one row keyed by the field's flat key. `App\Support\RuntimeConfig` then lays
those rows over the config repository, so a call site goes on reading
`config('chat.default.slowModeSeconds')` and never learns that an administrator changed it.
Adding a knob is one entry in `config/settings.php`, not a form, a request class and a page
change.

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

## The two buckets

The application knows two S3 disks and they are not interchangeable.

| Disk | Holds | Configured in |
|---|---|---|
| `s3` | Branding logo and favicon, emotes, show and recording thumbnails | `.env`, `AWS_*` |
| `dvr` | The HLS segment archive and the generated recording playlists | Archive storage > Archive bucket |

Everything on `s3` resolves the disk by name at the call site, and it has to answer before
anyone can reach this panel - the login screen's logo is on it. So it stays ops
configuration and the panel does not offer it.

Archive storage > Disk says which of the two the archive itself is read from. `dvr` is
production: a bucket of its own, with its own credentials. `s3` is for an installation
running one bucket for everything, which is what local development does rather than
configure `DVR_AWS_*` a second time. Set it to `s3` and the Archive bucket card goes
inert, because those seven fields write the `dvr` disk whatever the archive is pointed at.

Quota is a number an operator types, and it is reported, never enforced. S3 has no
free-space call and no provider this runs against exposes a quota over the API, so nothing
can discover the limit and nothing blocks a write at it. Left at zero the recordings page
shows what is stored and says nothing about what is left. The totals come from an hourly
scan of the bucket, cached with the time it was taken; the page never scans on demand.

## Secrets

A field can be stored encrypted at rest. Two kinds, and the difference is who the secret
belongs to:

- **Write-only** - the pretalx token, the Telegram bot token, the archive bucket's key and
  secret, and the two playback token secrets. These are values
  somebody else issued, or values the edges hold a copy of. The pane shows a mask, saving
  the mask leaves the stored value alone, and a Clear control beside it removes it. They
  are never read back out of the panel or out of `branding:set --list`. A sign-in
  provider's client secret behaves the same way on its own form, though it is a row rather
  than a settings key.
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
