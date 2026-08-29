# Sign-in

Four switches at `/manage` > Settings > **Sign-in**. They are independent and every
combination is valid, including all of them at once.

| Switch | What it decides |
|---|---|
| Require sign-in to watch | Whether a guest may browse and watch without an account |
| Identity provider | OpenID Connect, with the provider's URL, client ID and secret beside it |
| Password accounts | Accounts this installation holds itself, with a password |
| Public registration | Whether anybody can create one of those, or only an administrator |

The first is not a way in. It is permission to browse without one, and it is why it does
not count when the other switches are checked.

Public registration sits on top of password accounts rather than beside them: with nowhere
to create an account, there is nothing to open. Switching password accounts off closes
registration with it.

## How the sign-in screen composes

The screen renders whatever is on, in this order:

1. The email and password form, when password accounts are on. Under it, "Forgot your
   password?" and, when public registration is on, "Create an account".
2. A divider, only when both the form and the provider button are showing.
3. The provider button, when the identity provider is on and has a URL and a client ID
   behind it. A switch on its own is not enough - a provider with no endpoint is a button
   that fails on the second page, so it does not render.
4. "Continue without signing in", when sign-in is not required.

With nothing on, the screen renders the branding copy and one line saying sign-in is not
configured. It never renders a control that cannot work.

Every form behind the screen is switched too. A mode that is off answers 404 at its
address, the same way a switched-off feature does, so a form nobody is offered is not
reachable by hand either.

## Accounts an administrator creates

`/manage` > Users > **New Account**. A name, an address and a password, plus any roles to
attach.

The address is confirmed on creation - an administrator typing it in is the confirmation -
so the account is a full one straight away.

On an existing account, the same page sets or clears a password. Clearing is refused on an
account that has no identity provider subject, because that is its only way in.

Creating an account and setting a password on one need `admin.access`, not `user.manage`:
both hand somebody a way in, which is the same bar as the pane that switches the modes.
Editing roles is still `user.manage`.

The identity fields - subject, name from the provider, registration id - stay read-only
however the account was made. A local account has no subject; that is the whole
difference between the two kinds.

## Accounts people create themselves

With public registration on, `/register` takes a name, an address and a password, signs
the account in, and sends a confirmation mail.

Nothing is gated on confirming. What confirmation decides is the baseline role - the one
that makes an account an attendee - which is withheld until the address is confirmed,
because a form open to the internet is not evidence that the address belongs to whoever
filled it in. An account the identity provider owns never comes through this: the provider
vouches for it and the sign-in mapping hands it the same role.

Nothing else is granted automatically. Every further role is an administrator's decision
in `/manage` > Users.

Address uniqueness is enforced against accounts that hold a password, not against every
row. The identity provider rewrites an account's address from its claim on every sign-in,
so two provider accounts sharing an address - a family address, a provider that recycles
them - has to stay possible.

## Passwords

Reset is the standard Laravel broker at `/forgot-password`, scoped the same way sign-in
is: only an account that holds a password of its own can have one set by a link. Anything
else would hand an account the identity provider owns a second way in that nobody asked
for.

An address nobody holds gets the same answer as one that does. On an installation whose
addresses come from a provider, telling the two apart tells a stranger which of them has
an account here.

Both the reset mail and the confirmation mail are branded from `/manage` > Settings > Look
and go out on the queue, so an installation whose mail is down does not answer 500 on a
request that half succeeded.

## With no mail configured

Password reset needs mail. There is no way around that: if nobody can send to the address,
nobody can prove it.

Self-registration does not. An account that registers itself is signed in immediately and
simply never gets the baseline role, and an administrator finishes the job: `/manage` >
Users > the account > **Confirm address**. It is the same decision, made by an
administrator instead of by a mail client, and it is held at the same bar as setting a
password.

An installation that cannot send mail should therefore leave public registration off and
create accounts in `/manage` > Users, where the address is confirmed on creation.

## Signing out

An account this installation holds signs out with a POST to `/logout`. An account the
identity provider owns leaves through the front channel instead, so the provider hears
about it too. The layout picks between them; there is nothing to configure.

## Locking yourself out

The settings pane sits behind `admin.access`, which needs somebody signed in, so a save
that switches the last usable mode off could not be undone from a browser. Three
safeguards:

- A save that leaves no sign-in mode on at all is refused. Guest access does not count.
- A save that leaves no mode any administrator can actually use is refused - switching
  password accounts off while no administrator has a provider subject, or the provider off
  while no administrator has a password. The check and the write happen under one lock, so
  two administrators saving at once cannot each pass their own check against a state the
  other is about to change.
- The Reset pane refuses for the same reason, measured against the shipped config rather
  than what is in force: reset deletes the rows, so provider details typed into the panel
  go with them.

Neither check can help with the case it never sees: a provider endpoint that stops
answering, an installation restored without its OIDC client, a first boot with no accounts
at all. That is what the recovery command is for.

## Recovery

```bash
php artisan auth:local-admin you@example.org
```

Creates or promotes a local administrator, prompts for a password, confirms the address,
attaches a role carrying `admin.access` - creating one if the installation has none - and
switches password sign-in on in the same breath. An account nobody is allowed to use is
not a recovery.

`--password=` skips the prompt, for a provisioning script. `--name=` sets the display name,
which otherwise defaults to the part before the `@`.

It only ever touches accounts this installation holds. An account the identity provider
owns keeps its subject and is left alone, even when it carries the same address.

The switch goes through the settings registry, the same write the panel makes, so
`/manage` > Settings > Sign-in shows password accounts on afterwards.

This is also how a fresh install gets its first administrator.
