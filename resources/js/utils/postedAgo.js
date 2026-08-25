/**
 * How long ago something was posted, in words.
 *
 * Rounded to the largest unit that still says something, and it stops at a date:
 * "posted 412 days ago" is a number nobody converts, and a comment under a
 * recording from two conventions back is old enough that the date is the fact.
 */
export function postedAgo(iso, reference = Date.now()) {
    if (!iso) return ''

    const posted = new Date(iso)
    const seconds = Math.max(0, Math.floor((reference - posted) / 1000))

    if (seconds < 60) return 'just now'

    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes} min ago`

    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`

    const days = Math.floor(hours / 24)
    if (days < 30) return `${days} ${days === 1 ? 'day' : 'days'} ago`

    return posted.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
