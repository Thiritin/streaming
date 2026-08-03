/**
 * Turns a raw chat message into render tokens.
 *
 * Messages are stored and broadcast as plain text; nothing here produces HTML, so
 * the renderer never needs v-html and user input can never inject markup.
 */

const EMOTE = /:([a-z0-9_]{2,20}):/gi
const MENTION = /@([\p{L}\p{N}_.-]{2,32})/gu
const URL_PATTERN = /https?:\/\/[^\s<]+|www\.[^\s<]+/gi

/**
 * @param {string} body raw message text
 * @param {object} options
 * @param {Record<string, string>} options.emotes name -> url
 * @param {string|null} options.currentUser name of the viewer, highlighted when mentioned
 * @param {string[]} options.allowedDomains domains that stay clickable
 */
export function tokenize(body, { emotes = {}, currentUser = null, allowedDomains = [] } = {}) {
    if (!body) return []

    const matches = []

    collect(matches, body, EMOTE, (match) => {
        const name = match[1].toLowerCase()
        const url = emotes[name]

        return url ? { type: 'emote', name, url } : null
    })

    collect(matches, body, URL_PATTERN, (match) => {
        const raw = match[0].replace(/[.,!?)]+$/, '')
        const href = raw.startsWith('http') ? raw : `https://${raw}`
        const host = hostOf(href)

        if (!host || !allowedDomains.some((domain) => host === domain || host.endsWith(`.${domain}`))) {
            return null
        }

        return { type: 'link', href, label: raw, length: raw.length }
    })

    collect(matches, body, MENTION, (match) => ({
        type: 'mention',
        name: match[1],
        self: !!currentUser && match[1].toLowerCase() === currentUser.toLowerCase(),
    }))

    matches.sort((a, b) => a.start - b.start)

    const tokens = []
    let cursor = 0

    for (const match of matches) {
        if (match.start < cursor) continue // overlapping match, the earlier one wins

        if (match.start > cursor) {
            tokens.push({ type: 'text', value: body.slice(cursor, match.start) })
        }

        tokens.push(match.token)
        cursor = match.end
    }

    if (cursor < body.length) {
        tokens.push({ type: 'text', value: body.slice(cursor) })
    }

    return tokens
}

/**
 * True when a message is nothing but a couple of emotes, which get rendered bigger.
 */
export function isEmoteOnly(tokens) {
    const emotes = tokens.filter((token) => token.type === 'emote')

    if (emotes.length === 0 || emotes.length > 3) return false

    return tokens.every((token) => token.type === 'emote' || (token.type === 'text' && !token.value.trim()))
}

/**
 * True when the viewer is mentioned anywhere in the message.
 */
export function mentionsUser(tokens) {
    return tokens.some((token) => token.type === 'mention' && token.self)
}

function collect(matches, body, pattern, build) {
    pattern.lastIndex = 0

    let match
    while ((match = pattern.exec(body)) !== null) {
        const token = build(match)

        if (!token) continue

        const length = token.length ?? match[0].length
        delete token.length

        matches.push({ start: match.index, end: match.index + length, token })
    }
}

function hostOf(href) {
    try {
        return new URL(href).hostname.toLowerCase()
    } catch {
        return null
    }
}
