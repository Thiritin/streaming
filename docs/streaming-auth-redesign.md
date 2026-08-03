# Streaming auth + delivery redesign

Status: proposal. Nothing here is implemented yet.

## What we do today

Playback goes through Laravel twice per playlist refresh:

1. `Source::getHlsUrl()` returns `route('hls.master')`, i.e. `/hls/{slug}/master.m3u8` on the app domain.
2. `HlsController::master()` resolves the viewer (session cookie, or `?streamkey=`), writes a heartbeat, picks an edge via `User::getOrAssignServer()`, then makes a **blocking server-side HTTP request** to that edge for the real master playlist and rewrites variant lines to `/hls/{variant}.m3u8?streamkey=…`.
3. `HlsController::variant()` repeats all of that, then rewrites every `.ts` line to an absolute edge URL with `?streamkey=…` appended.
4. Edge nginx runs `auth_request /auth` on `.ts` only, which calls `/api/hls/auth`. `HlsSessionController::auth()` identifies the viewer from streamkey / `hls_ctx` / an IP-keyed cache fallback, checks the source is `ONLINE`, and mints a session UUID.

Edge selection is sticky per user row: `users.server_id` + `users.streamkey`, set by `assignServerToUser()`, backfilled by `ServerAssignmentJob`, and announced by the `ServerAssignmentChanged` broadcast.

## Why it needs to change

**PHP is in the media hot path.** A viewer refetches the variant playlist every segment duration (~2-4s). At 5,000 viewers that is roughly 1,250-2,500 requests/sec into Laravel, and each one performs an outbound HTTP call to an edge before it can answer. Two network hops and a PHP worker per playlist refresh is the single biggest scaling wall.

**The playlist caches are per-user, so they barely work.** Cache keys are `hls_master:{stream}:{host}:{port}:{streamkey}` and `hls_variant:{variant}:{host}:{port}:{streamkey}`. N viewers produce N cache entries for identical content. The playlist body is the same for everyone except for the streamkey embedded in it, which is exactly the thing that should not be in there.

**Segment auth hits PHP per segment.** The nginx auth cache key is `"$remote_addr:$arg_streamkey:$uri"`. `$uri` changes for every segment, so the 1-minute `proxy_cache_valid` never helps: every viewer, every segment, one subrequest to Laravel.

**Heartbeat writes are on the read path.** `trackUserAccess()` is guarded by a 60s cache key, but when it does fire it runs a `SourceUser` upsert *and* a table-wide `UPDATE … SET left_at` across the source. That cleanup does not belong in a request.

**streamkey is a permanent bearer credential in a query string.** 32 random chars, never rotated, never expiring, no revocation. It is written into playlist bodies, into edge nginx access logs, into browser history, and into an nginx cache key. One leaked link is permanent free access to the stream.

**Playlists are unauthenticated at the edge.** Only `.ts` has `auth_request`. Anyone who knows an edge hostname can pull `/live/{slug}_fhd.m3u8` directly; the Laravel proxy is the only thing gating playlists, and it is bypassable.

**Four identity mechanisms.** Session cookie, streamkey, `hls_ctx`, and an IP+stream cache fallback. The IP fallback is unsound behind CGNAT or convention NAT, where hundreds of attendees share an address.

**Sticky per-user edge assignment is the wrong primitive.** It costs a DB write per viewer, needs a broadcast when a server drains, load-balances on a `viewer_count` that lags by up to a heartbeat interval, and pins a user globally rather than per playback session.

## Target architecture

One principle: **Laravel authorizes once, edges enforce statelessly, PHP never touches the media path.**

### 1. Signed playback token replaces streamkey

Issue a short-lived signed token instead of a permanent secret. Claims:

```
{
  typ:  "viewer" | "embed",
  src:  <source slug>,          // exactly one, never a wildcard; this IS the entitlement
  sub:  <user id>,              // viewer tokens
  kid:  <embed key id>,         // embed tokens, checked against a pushed allowlist
  edge: <edge hostname>,        // which edge this session is pinned to
  sid:  <playback session uuid>,// for counting, not for auth
  exp:  <unix ts>               // 15 minutes out; absent on embed keys
}
```

Encoded as `v1.base64url(claims json).base64url(hmac_sha256(body, secret))`, where the signed body includes the version prefix so it cannot be downgraded. Every edge holds the shared secret and verifies **locally, in-process, with no network call**. `exp` is the revocation mechanism.

Laravel mints this in the page controller and hands it to the player as an Inertia prop, so there is no extra request to get one.

### 2. Token transport

Two viable options; they differ mostly in operational cost.

**Option A - cookie (cleanest).** Set the token as a cookie scoped to the edge domain, `Secure`, `HttpOnly`, `SameSite=None`. URLs then contain no credential at all, so playlist and segment URLs are byte-identical for every viewer and nginx/CDN cache hit rate approaches 100%. Requires: edges verify the cookie (see enforcement below), CORS switches from `Access-Control-Allow-Origin: *` to a specific origin plus `Access-Control-Allow-Credentials: true` (the wildcard is illegal with credentials), and hls.js needs `xhrSetup` with `withCredentials = true`.

**Option B - query parameter.** `?t=<token>`. Works everywhere with no CORS or cookie-domain work, and cache keys already exclude query args (`proxy_cache_key "$scheme$proxy_host$uri"`), so shared caching still works. Cost: the token appears in logs and browser history. Acceptable *because* it expires in 15 minutes, which is the whole point of dropping the permanent streamkey.

Recommendation: ship Option B first (it is a drop-in replacement for the current `?streamkey=` shape and needs no CORS changes), then move to cookies once edges are verifying locally.

### 3. Edge enforcement point

The check must happen on the edge with no callback to Laravel. Ranked:

- **nginx `secure_link` module** - already compiled into the standard nginx build, so no image change. Verifies an expiring signed URL entirely in nginx. Limitation: MD5-based and the "payload" is whatever you can encode into the URL, so entitlement claims get crude. Fastest path to killing the PHP subrequest.
- **nginx + njs (`ngx_http_js_module`)** - real HMAC-SHA256 over a real JSON payload, still all in nginx. Needs `nginx-module-njs` added to the edge image (currently `nginx:alpine`, per `install.sh`). This is the option that fits the token design above properly.
- **Caddy** - Caddy already fronts nginx on every edge (`8080` -> `8081`), so it is a natural gate, but JWT verification needs an `xcaddy` custom build.
- **OpenResty + lua-resty-jwt** - most flexible, biggest change to the edge image.

Recommendation: **njs**, with `secure_link` as the fallback if adding the module to the edge image turns out to be painful. Whichever we pick, enforcement moves to `.m3u8` **and** `.ts`, closing the current playlist hole.

### 4. Delete the Laravel HLS proxy

The player points at the edge directly: `https://{edge}/live/{slug}_master.m3u8`. The master playlist is static per source (it only changes when the variant set changes) so it can be generated once and cached for 30s. Variant playlists keep relative segment paths, so there is nothing to rewrite. `HlsController` goes away entirely, and with it the outbound HTTP call, the regex rewriting, and the per-user playlist cache.

### 5. Edge selection: session-sticky weighted pick, no DB state

Per-request round robin is wrong for HLS: a viewer bouncing between edges mid-stream loses segment cache locality and can land on an edge whose playlist is a few segments behind, which the player sees as a stall. What we want is stickiness *for the duration of a playback session*, without a database column.

At page render, pick an edge weighted by current free capacity (from the cached `viewer_count` the edges already report), and put the hostname in the token's `edge` claim. The token carries the assignment, so there is no `users.server_id`, no write, and no broadcast. Token refresh reuses the same edge unless that edge is draining, in which case the next token moves the viewer and the player reloads the manifest.

Deletions this enables: `users.server_id` and `users.streamkey` columns, `User::assignServerToUser()`, `User::getOrAssignServer()`, `ServerAssignmentJob`, `ServerAssignmentChanged`, and the `has_server_assignment` Inertia prop.

Longer term, a **CDN or anycast layer in front of the edges** is strictly better: once segment URLs are user-agnostic and `immutable`, they cache trivially, and edge selection stops being our problem. Worth costing out separately.

### 6. Viewer counting off the request path

Edges already POST aggregate counts to `/api/hls/heartbeat`. Extend that to be the only counting mechanism: an agent on each edge tails the nginx access log, counts distinct `sid` claims per stream over the last 30s, and posts every 10-15s. That is one request per edge per 15s instead of one per viewer per segment.

For per-attendee presence (which attendee watched which show), use the **Reverb presence channel the player is already joined to**. It is accurate, free, and completely decoupled from HLS. The `SourceUser` heartbeat writes come off the request path, and the stale-session sweep moves into the existing `CleanupStaleViewerSessionsJob` schedule.

### 7. Cache policy, top to bottom

| Layer | Policy | Note |
|---|---|---|
| Master playlist | `max-age=30` | static per source |
| Variant playlist | `max-age=1`, `stale-while-revalidate`, `proxy_cache_lock on` | cache key `$uri` only, already correct |
| Segments | `max-age=31536000, immutable` | requires globally unique segment names; currently only 2m |
| Token verification | none needed | local HMAC, no cache to invalidate |
| Source-online check | removed | if a source drops, the playlist stops advancing and the player handles it |

If we ever move to LL-HLS, partial segments break the immutable-segment assumption and this table needs revisiting.

### 8. Expiry, refresh, and revocation

A 15-minute TTL is the revocation mechanism: a ban takes effect within 15 minutes at worst, at zero hot-path cost. Expiry must never be visible to a viewer, which takes three layers.

**Push refresh at T-3min.** The server pushes a fresh token over the Reverb private user channel the player is already joined to. No polling, no `fetch()`.

**60s skew grace at the edge.** Edges accept a token up to 60 seconds past `exp`. Absorbs clock drift and a slow refresh. Stateless, so it costs nothing.

**403 recovery.** If the WebSocket is down and the token genuinely expires, the next playlist fetch 403s and hls.js raises `manifestLoadError` / `fragLoadError`. The player responds with `router.reload({ only: ['playbackToken'] })` - an Inertia visit, not a raw `fetch()`, so it respects the project rule. Buffer is ~20-30s and the round trip is a few hundred ms, so the viewer sees nothing.

If that reload comes back with no token (logged out, banned, ticket revoked), that is the intended kill: tear down the player and show a "session ended" overlay.

Token expiry is not session expiry. The Laravel session cookie stays long-lived; the token is a short-lived capability derived from it.

#### Rotation with query-param transport

A query param does not inherit into relative segment URLs, and rotating it means changing URLs already in flight. hls.js runs `xhrSetup` *after* `xhr.open()`, so the URL cannot be mutated there. A custom loader is required:

```js
class TokenLoader extends Hls.DefaultConfig.loader {
    load(context, config, callbacks) {
        context.url = withToken(context.url, currentToken.value);
        super.load(context, config, callbacks);
    }
}
```

It reads `currentToken` on every request, so a Reverb push rotates transparently with no manifest reload. Written once, ~15 lines. This is a real cost of query-param transport that cookie transport would not have; it is accepted because embed keys (below) cannot use cookies at all, so the query-param path has to exist regardless.

### 9. Embed keys for VRChat and other integrations

Long-lived embeds are a **separate token type**, not a viewer token with a distant `exp`.

| | Viewer token | Embed key |
|---|---|---|
| `typ` | `viewer` | `embed` |
| `sub` | user id | embed key id |
| `src` | slug or `*` | one slug, never `*` |
| TTL | 15 min | no `exp`; stable for the world's lifetime |
| Revoke | let it expire | edge-pushed allowlist |
| Edge | weighted pick per session | pinned to one edge |
| Secret | `HLS_VIEWER_SECRET` | `HLS_EMBED_SECRET` |

Separate secrets, so a leak of the viewer secret cannot mint embed keys.

**Revocation is mandatory.** Long-lived plus leaked is exactly the streamkey failure we are removing. Each embed key carries a `kid`, and edges hold a small allowlist of valid `kid`s refreshed from Laravel every 30-60s - one request per edge per minute, cheap because embed keys are a handful of rows rather than thousands of users. Revoking removes the row and propagates within a minute. The allowlist entry also carries per-key policy the edge enforces locally: rate limit, max concurrent, allowed `Referer`.

**VRChat constraints that shape this:**

- VRChat video players (AVPro / Unity) use their own HTTP stack. No cookies, no JS. Query param is the only transport that works.
- World creators bake a **static URL** into the world; it can never rotate. So embed keys must not lean on `exp` - an `exp` at event end forces re-baking the world every year. Stability is the feature; revocation happens through the `kid` allowlist instead.
- AVPro on Quest/Android handles ABR switching poorly. Hand embeds a **fixed rendition** (`{slug}_hd.m3u8`) rather than a master with a full ladder.
- Serve embeds from a distinct host/path, e.g. `https://embed.stream.../live/{slug}.m3u8?k=<key>`, so they can be rate-limited and cached separately and a leaked embed key cannot be swapped for a viewer token or reach an attendee-restricted source.

**Capacity.** Embed load is expected to be small, so no separate edge pool. Each key gets a nullable `edge_server_id` and is **pinned to a single chosen edge**; a viral world then cannot spill onto the edges serving attendees. Null falls back to the normal weighted pick.

Pinning has one failure mode that matters more here than for viewers: the baked URL cannot be changed, so if the pinned edge is deprovisioned or dies, the embed goes dark with no client-side recovery. Guards:

- The embed URL must stay on the **embed hostname**, never an edge hostname. `edge_server_id` decides where that hostname *resolves or proxies*, so re-pinning is a DNS or config change rather than a URL change.
- Block deprovisioning an edge that has embed keys pinned to it, or force a re-pin first. `Server::isInUse()` and the deprovision flow need to know about embed keys.
- Health check the pinned edge and alert in `/manage` when a key's edge is unhealthy, since nothing on the VRChat side will report the failure.

**Management** lives in `/manage`, not Filament (Filament is being phased out here). Resourceful Inertia CRUD matching the existing pattern - `Manage\EmbedKeyController`, `App\Http\Requests\Manage\*`, `resources/js/Pages/Manage/EmbedKeys/*`, routes under `manage.embed-keys.*`. Create, name, pin to an edge, revoke, plus last-used and live viewer count per key. Names like "VRChat main stage", "Second Life lobby", "hallway display".

### 10. Entitlements

**Revised during implementation: there is no `ent` claim.** Restricted-show access is role-based (`Show::$required_roles` checked via `User::hasAnyRole()`), and an edge has no way to know a show's required roles without asking Laravel, which is the thing we are removing. Instead the token is **bound to exactly one source slug and never a wildcard**, entitlement is checked once at mint time with `canBeAccessedBy()`, and the binding is what carries that decision to the edge. The edge only has to check three things, all local: signature valid, not expired, `src` matches the slug being requested.

Consequence: switching source means a new token. That is free, because switching means an Inertia page visit, which re-renders with a fresh token anyway.

A user's entitlement change takes effect on the next mint, bounded by the same 15-minute TTL.

Original plan, kept for context - restricted-show access (`canBeAccessedBy`) becomes the `ent` claim, so the edge decides locally instead of asking Laravel. Changing a user's entitlement invalidates nothing directly; it takes effect on the next token issue, bounded by the same 15-minute TTL.

## Decisions made

1. **Edge enforcement: nginx + njs.** Real HMAC-SHA256 over a JSON payload, verified in-process on the edge. Requires adding `nginx-module-njs` to the edge image (currently `nginx:alpine`, written by `install.sh`). `secure_link` stays as the fallback if the image change proves painful.
2. **Edge routing: app-side weighted pick.** Laravel chooses an edge at page render, weighted by reported free capacity, and puts the hostname in the token's `edge` claim. Session-sticky with no DB state. A CDN or anycast layer in front remains the better long-term answer and should be costed separately.
3. **Token transport: query param.** `?t=<token>`. Drop-in for the current `?streamkey=` shape, no CORS work, and cache keys already exclude query args. Also the only transport VRChat's player can use. Cookies stay an option for viewer tokens later.

## Rough sequencing

1. ~~Token mint + verify in Laravel, issued alongside the existing streamkey. Nothing breaks.~~ **Done.** `PlaybackTokenService`, `PlaybackToken`, `PlaybackTokenTypeEnum`, `InvalidPlaybackTokenException`, `stream.token.*` config, and a `playback` Inertia prop on `ShowPlayer` / `ExternalStream` that nothing consumes yet. Inert until `HLS_VIEWER_SECRET` is set.
2. Add njs to the edge image; enforce on `.m3u8` and `.ts`, accepting *either* token or streamkey.
3. Point the player at edge URLs directly via `TokenLoader`; delete `HlsController`.
4. Reverb token push at T-3min, 60s edge skew grace, and the 403 -> `router.reload` recovery path.
5. Move counting to log-tailing plus Reverb presence; take the heartbeat writes out of the request path.
6. Switch edge selection to the token claim; drop `users.server_id`, `ServerAssignmentJob`, and friends.
7. Embed keys: model + `kid` allowlist endpoint, edge allowlist refresh, `/manage` CRUD with edge pinning.
8. Stop issuing streamkeys; drop the column and the streamkey acceptance path.
9. Segment cache lifetime to immutable once segment names are unique.
