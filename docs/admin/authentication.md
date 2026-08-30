# Sign-in

Everything here is at `/manage` > Settings > **Sign-in**, in three cards down the page.

**Sign-in Methods** is four switches, because "how does somebody get in" is one question:

| Switch | What it decides |
|---|---|
| Guest Access | Whether a guest may browse and watch without an account |
| Password | Accounts this installation holds itself |
| Registration | Whether anybody can create one. Indented under Password, and not offered while it is off |
| OAuth2 | Whether the sign-in providers are offered at all |

Beside them, a link to the providers themselves - they are rows rather than switches, so
they have a page of their own.

**Provider pages** is what the provider is called and where it sends people to register or
sign out. **Login screen** is the copy on the sign-in page.

Guest Access is not a way in. It is permission to browse without one, which is why it never
counts when the lockout guard asks whether anybody can still sign in. It reads as its own
opposite - the switch is on when guests may watch, while the column stores whether sign-in
is required. Only the page inverts it; every reader in the application is unchanged.

Public registration sits on top of password accounts rather than beside them: with nowhere
to create an account, there is nothing to open.

OAuth2 is the master switch over every provider. Off, no provider button is offered however
many rows are enabled, which is the one control that turns the whole list off without
editing it.

## Providers

A provider is a row in `auth_providers`, not a settings key. The settings registry
declares a static field list, so N providers with M fields each cannot live in it, and a
delete would have to sweep an unknown key prefix. Events and categories set the same
precedent: a settings area whose contents are rows.

Managed at `/manage/settings/providers`, reached from the link on the Sign-in Methods card.
There is no menu entry of its own - a provider is part of how people sign in, and the card
is where an operator is already looking.

Each row carries a driver, a URL key, a label, the client ID and secret, optional scopes,
whether it is switched on, and where it sits in the button order. Adding one is a driver, a
key and a label; the toast then tells you the callback URL to register at the provider.

### The key, and the callback URL

`key` is the URL segment: `/auth/{key}/redirect` and `/auth/{key}/callback`. It is never
derived from the label, because the label is editable and the callback URL is registered on
somebody else's system.

The one exception is the convention's own provider, seeded on upgrade. That row keeps
`/auth/callback`, the URI already registered at the identity provider, and `/auth/login`
still works, so every link in the wild and every bookmark keeps working. The row is
resolved by the path it claims rather than by anything in the URL.

A provider that is switched off is not a route: its redirect answers 404, the same way
every switched-off feature in this application closes.

### Drivers

Laravel Socialite's core OAuth2 drivers, plus a generic OIDC driver written for this app:

| Driver | For |
|---|---|
| Custom OIDC | Any provider with an OpenID Connect discovery document, or with its endpoints typed in by hand |
| Google, GitHub, GitLab, Bitbucket, Facebook, LinkedIn, Slack, X | Socialite core |

Socialite's OAuth1 drivers are deliberately absent - they need a different constructor
shape than the runtime path this uses.

**Discord, Twitch and the rest of `socialiteproviders/` are not core.** Adding one is
`composer require socialiteproviders/discord` plus one line in
`App\Services\Auth\ProviderFactory::DRIVERS`. Discord publishes no discovery document, so
the generic OIDC driver does not cover it - it needs the package.

Custom OIDC takes an issuer URL and fetches the discovery document from it, cached per
provider. A provider whose discovery is wrong or absent can have its authorize, token and
userinfo endpoints set explicitly instead.

### Roles

Role mapping is per provider. A rule names a claim (`groups` from userinfo, `packages`
from the registration system, or anything else the provider publishes), a match of `exact`
or `contains`, a value, and a role. `contains` is what package strings like
`day-supersponsor-2026` need; the longest matching value wins, so a sponsor rule does not
swallow every supersponsor package.

A rule points at a role by id, never at the role's `external_id`. That column has no notion
of who said it, so under the old mechanism a second provider releasing a group literally
named `staff` would have granted this installation's staff role. It survives only as the
seed for the convention provider's map.

Each provider also has a **grants baseline** switch, which is what makes an account an
attendee.

Signing in through one provider never strips what another granted. What each provider
granted is written onto its own identity row, and the account holds the union; a role no
provider has ever granted is never touched, whoever assigned it.

### Deleting a provider

Refused while any account signs in through it. The foreign key cascades, so one click would
take every identity with it and orphan hundreds of accounts at once. Switch it off instead -
that is reversible, and it is what the disabled Delete button says. Deleting is only for a
row nobody has used.

## How the sign-in screen composes

Top to bottom: the email and password form when password accounts are on, with "Forgot your
password?" and, when registration is on, "Create an account"; a divider, only when there is
both a form and at least one provider button; one button per enabled provider, in the order
the rows set; and "Continue without signing in" when sign-in is not required.

A provider with no client credentials, or a custom OIDC row with no issuer and no endpoints,
is not offered - a button with nothing behind it fails on the second page.

With nothing on at all, the screen renders the branding copy and one line saying sign-in is
not configured. It never renders a control that cannot work.

Every form behind the screen is switched too. A mode that is off answers 404 at its address,
so a form nobody is offered is not reachable by hand either.

## When an address is already taken

Signing in with a provider whose email already belongs to an account here is **blocked**.
No account is created and no session is started:

> That address already belongs to an account here. Sign in to it, then add {provider} from
> your settings.

Nothing is ever merged automatically. Any case-insensitive match on an existing address
blocks - there may be several matches and nothing to pick between, our own
`email_verified_at` does not discriminate because every provider sign-in sets it, and the
provider's own verified flag is not uniformly available: Socialite's user contract exposes
none, Google puts it in the raw response, GitHub publishes none at all. A rule built on a
flag half the drivers lack would behave differently per driver.

The cost of blocking is one person doing a manual connect from their settings. The cost of
not blocking is two strangers sharing an account.

This will generate support contacts on the day a second provider is switched on, because
the blocked person's mental model is "I have a Google account". The copy is the whole
mitigation, and the fix is the same every time: sign in the way you already can, then
connect the second provider.

A provider response with no email at all never collides. The account is created without an
address, which is the already-handled case - mail is skipped for it.

**A provider that changes somebody's subject** - a re-issued `sub` after an IdP migration,
say - makes that account unreachable through it, and the new subject is blocked rather than
silently merged. That is the correct failure. Matching on email to repair a changed subject
would turn every provider's email claim into a login credential.

## A viewer's own ways in

`/settings` > **Connections**. One row per provider: connect, disconnect, and whether this
account could afford to lose that one. A provider an administrator has since switched off
still appears while the account holds an identity on it, or there would be no way to
disconnect it.

The same page sets a password on the account, when password sign-in is on. An account with
one provider and no password would otherwise never be able to satisfy the disconnect rule
without asking an administrator.

Connecting is not a second OAuth path: it puts the intent in the session and hands over to
the same redirect the sign-in button uses, so there is one flow, one callback, and one place
the collision rule lives. Two refusals on the way back, both of them flashed onto the
Connections page:

> That {provider} account is connected to a different account here.

> This account is already connected to {provider}.

**Disconnecting the last way in is refused** — "That is the only way into this account."
Sign-in methods are the identities the account holds plus a password if it has one, and the
count may never reach zero. The same rule governs an administrator clearing a password in
`/manage` > Users.

## Accounts an administrator creates

`/manage` > Users > **New Account**. A name, an address and a password, plus any roles to
attach. The address is confirmed on creation - an administrator typing it in is the
confirmation - so the account is a full one straight away.

The same page sets or clears a password on an existing account. Clearing is refused when it
would leave no way in.

Creating an account and setting a password on one need `admin.access`, not `user.manage`:
both hand somebody a way in, which is the same bar as the pane that switches the modes.
Editing roles is still `user.manage`.

## Accounts people create themselves

With public registration on, `/register` takes a name, an address and a password, signs the
account in, and sends a confirmation mail.

Nothing is gated on confirming. What confirmation decides is the baseline role - the one
that makes an account an attendee - which is withheld until the address is confirmed,
because a form open to the internet is not evidence that the address belongs to whoever
filled it in. An account that arrives through a provider does not come through this: the
provider vouches for it and its role map hands over the same role.

Nothing else is granted automatically. Every further role is an administrator's decision in
`/manage` > Users.

Address uniqueness is enforced in validation against accounts that hold a password, not by a
database index, because a provider rewrites its own identity row's address on every sign-in
and two provider accounts sharing an address has to stay possible.

## Passwords

Reset is the standard Laravel broker at `/forgot-password`, scoped to accounts that hold a
password of their own. Anything else would hand an account a provider owns a second way in
that nobody asked for.

An address nobody holds gets the same answer as one that does. On an installation whose
addresses come from a provider, telling the two apart tells a stranger which of them has an
account here.

Both the reset mail and the confirmation mail are branded from `/manage` > Settings > Branding
and go out on the queue, so an installation whose mail is down does not answer 500 on a
request that half succeeded.

## With no mail configured

Password reset needs mail. There is no way around that: if nobody can send to the address,
nobody can prove it.

Self-registration does not. An account that registers itself is signed in immediately and
simply never gets the baseline role, and an administrator finishes the job: `/manage` >
Users > the account > **Confirm address**. It is the same decision, made by an administrator
instead of by a mail client, and held at the same bar as setting a password.

An installation that cannot send mail should leave public registration off and create
accounts in `/manage` > Users, where the address is confirmed on creation.

## Signing out

An account with a password signs out with a POST to `/logout`. An account that signed in
through a provider with a front-channel logout leaves that way instead, so the provider
hears about it too. The layout picks between them; there is nothing to configure.

## Locking yourself out

The settings pane and the provider CRUD both sit behind `admin.access`, which needs somebody
signed in, so a change that switches the last usable way in off could not be undone from a
browser. The guards:

- A save that leaves no sign-in mode on at all is refused. Guest access does not count.
- A save that leaves no way in an administrator can actually use is refused - switching
  password accounts off while no administrator holds an identity on an enabled provider, or
  the other way round.
- The same check runs on the provider CRUD's update **and** its delete, because switching
  off the last enabled provider from its own page is a lockout the settings save never sees.
- Reset refuses for the same reason.

Each of those is checked and written under one lock, so two administrators saving at once
cannot each pass their own check against a state the other is about to change.

None of it helps with the case none of them can see: a provider endpoint that stops
answering, an installation restored without its client credentials, a first boot with no
accounts at all.

## Recovery

```bash
php artisan auth:local-admin you@example.org
```

Creates or promotes a local administrator, prompts for a password, confirms the address,
attaches a role carrying `admin.access` - creating one if the installation has none - and
switches password sign-in on in the same breath. An account nobody is allowed to use is not
a recovery.

`--password=` skips the prompt, for a provisioning script. `--name=` sets the display name,
which otherwise defaults to the part before the `@`.

It only ever touches accounts this installation holds. An account that has an identity on a
provider keeps it and is left alone, even when it carries the same address.

The switch goes through the settings registry, the same write the panel makes, so the
Password sign-in card shows it on afterwards.

This is also how a fresh install gets its first administrator.

## Upgrading an existing installation

Automatic, in one migration, and nothing is destructive.

The convention's identity provider becomes the first row in `auth_providers`, built from
the saved `oidc_*` settings rows first and the environment second - the same order that was
already in force. Those settings rows are then deleted, so the table is the only source and
a second one cannot disagree. The row keeps `/auth/callback`, so nothing has to be
re-registered at the provider.

Every account with a subject gets an identity on that row. `users.sub` was unique, so the
backfill cannot collide and cannot fail on a live database, which is why it runs before
anything reads the new tables. The column stays where it is as a legacy field and is dropped
in a later release.

The `oidc_url`, `oidc_client_id`, `oidc_secret` and `auth_oidc` fields are gone from the
settings pane; `enabled` on each provider row replaces the last of them.
`config('services.oidc.*')` and the `OIDC_*` environment variables are the seed for that
first row and nothing reads them afterwards.

**Do not switch on a second provider before you have set up the first one's role map.**
Until a provider has one, roles still come from the old mechanism, which matches a group
string with no notion of which provider released it.

## Where the secrets live

A provider's client secret is encrypted by an Eloquent cast on the row. That is a second
at-rest mechanism beside the one the settings table uses, and both are against `APP_KEY`, so
a key rotation has to cover both.

They are not otherwise the same. A settings secret reaches the config repository, which is
why `config:cache` is handed the shipped config with the overlay switched off. A provider
secret never reaches the config repository at all, so `config:cache` was never a risk for
it - and the protection the settings side has does not apply here because nothing needs it.
