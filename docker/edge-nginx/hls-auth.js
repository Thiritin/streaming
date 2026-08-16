/*
 * Edge-side playback token verification.
 *
 * This runs inside nginx via njs and is the fast path that keeps PHP out of the
 * media hot path: a request carrying `?t=<token>` is verified here with a local
 * HMAC and never touches Laravel.
 *
 * A request carrying the legacy `?streamkey=` falls back to the old
 * /api/hls/auth subrequest, so nothing breaks while both credentials are in
 * circulation. That fallback goes away when streamkeys do.
 *
 * Keep in sync with app/Services/PlaybackTokenService.php - the two must agree
 * on the wire format exactly. See docs/streaming-auth-redesign.md.
 */

const crypto = require('crypto');

const TOKEN_VERSION = 'v1';

/* Internal location that proxies to Laravel, used only for legacy streamkeys. */
const LEGACY_AUTH_LOCATION = '/auth-legacy';

/*
 * Mirrors the slug pattern in HlsSessionController. Source slugs are kebab-case
 * so the underscore is safe as the quality separator:
 *   /live/main-stage_master.m3u8
 *   /live/main-stage_fhd.m3u8
 *   /live/main-stage_fhd_00042.ts
 */
const SLUG_PATTERN = /^\/live\/([^\/_]+?)(?:_(?:master|fhd|hd|sd|ld))?(?:\.|_)/;

function env(name, fallback) {
    const value = process.env[name];

    return value === undefined || value === '' ? fallback : value;
}

/* Matches stream.token.leeway so both ends allow the same grace past expiry. */
function leewaySeconds() {
    const parsed = Number(env('HLS_TOKEN_LEEWAY', '60'));

    return isFinite(parsed) ? parsed : 60;
}

function secretFor(type) {
    if (type === 'viewer') {
        return env('HLS_VIEWER_SECRET', '');
    }

    if (type === 'embed') {
        return env('HLS_EMBED_SECRET', '');
    }

    return '';
}

function nowSeconds() {
    return Math.floor(Date.now() / 1000);
}

function splitUri(requestUri) {
    const separator = requestUri.indexOf('?');

    if (separator === -1) {
        return { path: requestUri, query: '' };
    }

    return {
        path: requestUri.substring(0, separator),
        query: requestUri.substring(separator + 1),
    };
}

function queryParam(query, name) {
    const parts = query.split('&');

    for (let i = 0; i < parts.length; i++) {
        const separator = parts[i].indexOf('=');

        if (separator === -1) {
            continue;
        }

        if (parts[i].substring(0, separator) === name) {
            try {
                return decodeURIComponent(parts[i].substring(separator + 1));
            } catch (e) {
                return null;
            }
        }
    }

    return null;
}

function sourceSlug(path) {
    const matches = path.match(SLUG_PATTERN);

    return matches === null ? null : matches[1];
}

function base64UrlDecode(value, encoding) {
    try {
        return Buffer.from(value.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString(encoding);
    } catch (e) {
        return null;
    }
}

/*
 * Compared over equal-length hex digests, so a mismatch never leaks where in
 * the digest it happened. Unequal lengths only occur on malformed input.
 */
function constantTimeEqual(a, b) {
    if (a.length !== b.length) {
        return false;
    }

    let difference = 0;

    for (let i = 0; i < a.length; i++) {
        difference |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }

    return difference === 0;
}

function reject(reason) {
    return { ok: false, reason: reason };
}

/*
 * Returns { ok, reason } or { ok: true, claims }. Reasons are for the error log
 * only; every failure answers 403 so a caller learns nothing from the response.
 */
function verifyToken(token, expectedSlug) {
    const parts = token.split('.');

    if (parts.length !== 3) {
        return reject('malformed');
    }

    if (parts[0] !== TOKEN_VERSION) {
        return reject('unsupported_version');
    }

    const payload = base64UrlDecode(parts[1], 'utf8');

    if (payload === null) {
        return reject('malformed');
    }

    let claims;

    try {
        claims = JSON.parse(payload);
    } catch (e) {
        return reject('malformed');
    }

    if (claims === null || typeof claims !== 'object') {
        return reject('malformed');
    }

    // The type picks which secret to check. Claiming the wrong one just fails
    // the signature below, so this cannot be used to cross the two secrets.
    if (claims.typ !== 'viewer' && claims.typ !== 'embed') {
        return reject('malformed');
    }

    const secret = secretFor(claims.typ);

    if (secret === '') {
        return reject('secret_missing');
    }

    const expected = crypto
        .createHmac('sha256', secret)
        .update(parts[0] + '.' + parts[1])
        .digest('hex');
    const actual = base64UrlDecode(parts[2], 'hex');

    if (actual === null || !constantTimeEqual(expected, actual)) {
        return reject('bad_signature');
    }

    // Viewer tokens must expire; embed keys are stable for a baked-in URL.
    if (claims.typ === 'viewer' && typeof claims.exp !== 'number') {
        return reject('missing_expiry');
    }

    if (typeof claims.exp === 'number' && nowSeconds() > claims.exp + leewaySeconds()) {
        return reject('expired');
    }

    if (typeof claims.src !== 'string' || claims.src === '') {
        return reject('malformed');
    }

    // The source binding is the entitlement: it was checked once at mint time.
    if (expectedSlug !== null && claims.src !== expectedSlug) {
        return reject('source_mismatch');
    }

    return { ok: true, claims: claims };
}

/*
 * auth_request handler. 204 allows the request, 403 denies it.
 *
 * $request_uri is the original client request line and is preserved across the
 * auth subrequest, so the query string is parsed from it rather than from
 * $arg_* to avoid any ambiguity about whose args those are.
 */
async function verify(r) {
    const target = splitUri(r.variables.request_uri || '');
    const slug = sourceSlug(target.path);

    if (slug === null) {
        r.error('hls-auth: unrecognised URI ' + target.path);
        r.return(403);

        return;
    }

    const token = queryParam(target.query, 't');

    if (token !== null) {
        const result = verifyToken(token, slug);

        if (result.ok) {
            r.return(204);

            return;
        }

        r.error('hls-auth: rejected token for ' + slug + ': ' + result.reason);
        r.return(403);

        return;
    }

    // Internal callers send the system key. Laravel's playlist proxy uses the
    // header so the URL stays identical for every viewer and the key never
    // reaches the access log; ffmpeg (thumbnail capture) can only put it in the
    // query string, so both are accepted.
    const systemKey = env('STREAM_SYSTEM_STREAMKEY', '');
    const streamkey = queryParam(target.query, 'streamkey');
    const headerKey = r.headersIn['X-Stream-Key'] || null;

    if (systemKey !== '') {
        if (headerKey !== null && constantTimeEqual(headerKey, systemKey)) {
            r.return(204);

            return;
        }

        if (streamkey !== null && constantTimeEqual(streamkey, systemKey)) {
            r.return(204);

            return;
        }
    }

    if (streamkey === null) {
        r.return(403);

        return;
    }

    // A per-user streamkey can only be resolved in the database, so this one
    // still costs a round trip to Laravel. Removed with the streamkey itself.
    try {
        const reply = await r.subrequest(LEGACY_AUTH_LOCATION, { args: target.query });

        if (reply.status >= 200 && reply.status < 300) {
            r.return(204);

            return;
        }

        // The app answers 404 when it thinks the stream is unknown or offline.
        // That is not an authorisation decision, so let the origin answer it -
        // it will 404 too if the segment really is gone. Reporting 403 here made
        // a stream restart look like an auth failure, and players treat a 403 as
        // fatal where they will retry around a 404.
        if (reply.status === 404) {
            r.return(204);

            return;
        }

        r.error('hls-auth: legacy auth returned ' + reply.status + ' for ' + slug);
        r.return(403);
    } catch (e) {
        r.error('hls-auth: legacy auth subrequest failed: ' + e.message);
        r.return(403);
    }
}

export default { verify };
