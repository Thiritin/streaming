# Server credentials

How a streaming server proves to the app that it is the server it claims to be, and what
to do when it stops being able to.

## Breaking change: every existing server has to be reinstalled

The credential a server presents is no longer stored in plaintext, is no longer accepted
in a query string, and is no longer what the app looks the server up *by*. The migration
drops the old `servers.shared_secret` column rather than carrying the values over, because
those values are in access logs and in cloud-init user-data and carrying them forward
would carry the exposure with them.

**Every running server stops talking to the app the moment the migration runs.** Nothing
that is on air stops: edges verify playback tokens locally with their own copy of the
token secrets, so viewers keep watching a box whose credential is stale, and the origin
keeps ingesting and keeps recording. What stops is the box's half of the conversation
with the app - heartbeats, metrics, config fetches, registration - so the dashboard turns
red and the server page reports **Credentials rejected**.

### What to do, per server

Do this from `/manage` > Servers > the server > **Install Script**, one server at a time.

1. Press **Rotate credentials** and confirm. This mints a new pair and stops the old one
   being accepted immediately.
2. **Download the script in the same session.** Only the hashes are stored, so the
   plaintext exists for exactly as long as the browser session that minted it. Come back
   tomorrow and the page will tell you the credentials were issued earlier and offer you a
   rotate, not a script.
3. Copy the script onto the box and run it as root. It rewrites `/opt/streaming/.env`,
   re-downloads every config file, and restarts the stack.

The script restarts the stack, so it is not a background job:

- **Edges: one at a time, and never the last one during a show.** An edge restarting is
  every viewer on it reconnecting. Viewers are reassigned to the remaining edges while it
  is down, so with one edge left there is nowhere to put them.
- **The origin: between shows.** Restarting it stops ingest and stops the DVR, so anything
  publishing at that moment is dropped and that gap is not in the archive afterwards.

### If you cannot get a shell on the box

Reprovision it from `/manage` > Servers. A new VM gets a freshly minted pair in its
cloud-init automatically, so there is nothing to copy by hand. Same rules about when:
one edge at a time, the origin between shows.

### Verifying

The server's row in `/manage` > Servers goes from **Credentials rejected** back to
**Recent** on the first request that authenticates - within a minute, because the
heartbeat cron runs every minute. The dashboard alert clears with it.

---

## How it works now

**Two credentials, both stored as SHA-256 hashes.** The app never holds the plaintext
after the render that mints it.

- `X-Shared-Secret` - what the box presents on every request to the app: config fetches,
  the install script download, registration, the heartbeat, and the stream play and stop
  hooks.
- A deploy token, issued alongside it and written to the box's `/opt/streaming/.env` as
  `DEPLOY_TOKEN`. Deploy authority and metrics authority are different powers and have to
  be revocable separately, so rotating one does not blind the other. Nothing consumes it
  yet; it is provisioned ahead of the endpoints that will.

**Identity first, credential second.** Every server endpoint carries the server in its
path - `/api/server/{server}/config/{type}`, `/api/server/{server}/heartbeat`, and so on -
and the presented credential is checked against *that* row. The app used to find the
server by the secret instead, which meant any box holding a valid one could address any
other box's endpoints: a compromised edge could ask for the origin's rendered config, and
that config carries the archive bucket's credentials.

**Header only.** The `?shared_secret=` form is gone. A credential in a query string is a
credential in the app's access log, in every proxy on the path, and in cloud-init's own
log on the box.

**Every refusal is the same 401.** An id that does not exist, a deleted server, a row with
no credential, a wrong credential and a missing header all answer identically. These
endpoints serve credentials, so an unauthenticated caller is not told which server ids are
real.

**`/api/file/{file}` is gone.** It was an older, second provisioning path that served the
origin's SRS config to anyone holding any valid secret. Everything it served is now behind
`/api/server/{server}/config/{type}`.

The endpoints are rate limited per server, well clear of a heartbeat a minute plus the
handful of config fetches an install makes. The limit sits in front of the credential
check rather than behind it, so a wrong credential is counted too.

The SRS callbacks at `/api/srs/*` are a separate path and are unchanged. A callback names
a stream, never a server, so it cannot go identity-first: it is authorised by the
publisher's stream key, or by a forwarding server's own secret, out of the RTMP query
string SRS already sends. That is the one lookup left that goes by credential, and it now
matches against the stored hash rather than against a plaintext column.

## Rotating on purpose

Same procedure as the upgrade above: **Rotate credentials**, download in the same session,
run on the box. There is no window in which both pairs are accepted - the old one stops
working the moment the new one is minted - so the box is down between the rotate and the
reinstall.

Rotating needs `stream.manage` or `admin.access`. It is a mutation, not a read, because it
stops a running box checking in.

A server that has never held a credential - an older row, a manually created one - is
given a pair the first time its install script page is opened. There is nothing to
invalidate, and the alternative is a page whose every script refuses to run.

## Reading the panel

**Credentials rejected** on a server's row means the app is turning that box away: the box
is alive and asking, and the credential does not match. It is a different fault from
**Stale**, which means nothing has arrived at all, and the panel says so rather than
showing the same red for both. The stamp is when the refusals started, not when the last
one happened, and it clears on the first request that authenticates.

Both show as a danger alert on the dashboard, under their own keys, so a rotation that
fixes a box posts a cleared line of its own rather than being folded into the heartbeat's.

## What a script without credentials does

A script rendered outside the session that minted the credentials carries no plaintext. It
does not install a box that cannot check in - it prints one line saying it was rendered
without credentials, tells you to rotate and download again, and exits.

## Reaching SRS on the origin

SRS has an HTTP API and a stats page, and both are bound to loopback in the origin's
compose file: `127.0.0.1:1985` for the API, `127.0.0.1:8082` for the stats page. They are
reachable from the box and from nothing else, so they are read over an SSH tunnel.

```bash
ssh -N -L 1985:127.0.0.1:1985 -L 8082:127.0.0.1:8082 root@origin.example.org
```

Then `http://127.0.0.1:1985/api/v1/streams` for what is publishing, and
`http://127.0.0.1:8082/` for the stats page. Leave the tunnel up for as long as you are
looking and close it afterwards.

**There is no console login.** The API is enabled with no authentication and never had
any: it is not exposed, so the tunnel is the access control. `SRS_USERNAME` and
`SRS_PASSWORD` were fields in Settings > Streaming and variables in `.env`, and nothing
applied them - the only thing that ever read them was the `/api/file/` provisioning path,
which is deleted. They are gone from both. **Delete them from any `.env` still carrying
them**; they do nothing.

Do not publish either port. Opening 1985 to the internet is handing over an unauthenticated
API that can drop a publisher.
