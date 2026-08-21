/**
 * How long a live show has been on, in words.
 *
 * A running clock reads as something to watch tick rather than as a fact about the
 * show, and by the second day of an event it is a five digit number nobody can
 * parse at a glance. This rounds to the largest unit that still says something.
 */
export function wentLiveAgo(startedAt, reference = Date.now()) {
    if (!startedAt) return null

    const seconds = Math.max(0, Math.floor((reference - new Date(startedAt)) / 1000))

    if (seconds < 120) return 'just went live'

    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `went live ${minutes} min ago`

    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `went live ${hours} ${hours === 1 ? 'hour' : 'hours'} ago`

    const days = Math.floor(hours / 24)

    return `went live ${days} ${days === 1 ? 'day' : 'days'} ago`
}

/** The same span, short enough for a tile's meta row: "live 23 min", "live 2 h". */
export function liveFor(startedAt, reference = Date.now()) {
    if (!startedAt) return null

    const seconds = Math.max(0, Math.floor((reference - new Date(startedAt)) / 1000))

    if (seconds < 120) return 'just started'

    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `live ${minutes} min`

    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `live ${hours} h`

    return `live ${Math.floor(hours / 24)} d`
}
