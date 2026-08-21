# Displays and screens

A display is any screen that plays a stream without an account: a TV in a corridor, a
laptop in a green room, VLC on a laptop behind the desk. It signs in once with a
display key and stays signed in until the key is revoked.

Two pages in `/manage` cover them:

- **Display Keys** - the credentials. One code per screen, or per person who needs a
  VLC URL.
- **Screens** - the screens themselves: what each one is playing right now, and where
  to send it.

## Setting a screen up

1. `/manage` > Display Keys > **New Display Key**, named after the wall it goes on.
2. Open the display URL on the screen once. The code leaves the address bar
   immediately, so nothing sensitive is left on show.
3. Press **Start playback**, or a source tile to start on that channel.

The setup page also carries the VLC URLs and the kiosk-mode command line for a screen
that has to survive a reboot.

## Seeing what a screen is playing

`/manage` > **Screens** lists every screen currently signed in, with what it reports it
is playing, how long ago it last checked in, and which key let it in. The list refreshes
by itself every ten seconds.

A screen reports itself on every poll, so the list is what the screen believes it is
showing, not what it was last told to show. A screen that stops polling drops off the
list after two minutes - it was unplugged, reimaged, or the browser was closed.

**Rename** gives a screen a name of its own. Worth doing as soon as one key is on more
than one wall, because the key name is otherwise the only handle on both.

## Sending screens to a source

When a big show is about to start, screens can be moved without walking to them.

- One screen: **Send To Source** on its row in Screens.
- A few screens: tick them and use the bulk **Send To Source**.
- Every screen on one key, for a room's worth of walls at once: **Send Screens To
  Source** on the row in Display Keys.
- Everything that is polling: **Send All Screens** at the top of Screens.

A screen picks the instruction up on its next poll, so give it about ten seconds. It
switches even if the target source has no show on it yet, which is the point: send the
walls before the doors open, they sit on **No show on air**, and the picture appears by
itself the moment the show goes live.

The instruction is spent as soon as the screen reports it arrived. After that anyone
standing at the screen can switch channel from the on-screen bar again, and the
automatic "this source went off air, fall back to the featured one" behaviour resumes.
Choosing **Leave where it is** withdraws an instruction that has not landed yet.

A screen sitting on the setup page cannot be started remotely - a browser will not go
fullscreen or unmute without someone touching it. It shows a **Sent to ...** prompt
instead, so whoever is there presses one button.

## What a screen may play

A channel is playable only while a show on it is live. Several sources send around the
clock without being for anyone to watch - a hall camera up through setup, a stage on
colour bars between slots - so ingest arriving is not what opens a channel.

A channel with no show on it is still listed on the setup page and in the on-screen bar,
greyed out and not selectable, and its VLC URLs are withheld until a show starts. A
screen already playing a channel moves off it as soon as the show there ends: to the
featured channel if that one is on air, otherwise to any channel that is, and if nothing
is on it waits on **No show on air**.

If a screen has to show a channel that has no programme against it - a lobby loop, a
holding card - put a show on that channel and press live. The show is what opens it.

## When a screen should lose access

Sending is not a security control. Two things on Display Keys are:

- **Sign out screens** keeps the code alive but drops every session already minted from
  it, for the screen that walked off. Screens that are fine need the code entered again.
- **Revoke** deletes the key. Every screen using it stops playing within a few seconds.

Either way the screens disappear from the Screens list, because the sessions they hold
have stopped resolving.
