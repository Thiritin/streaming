import { computed, onMounted, onUnmounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Subscriber counts per channel name.
 *
 * The player mounts the desktop chat and the mobile drawer at the same time, so a
 * plain `Echo.leave()` from one instance would silence the other. The channel is only
 * left once the last instance using it goes away.
 */
const subscriptions = new Map()

/**
 * A message safe to show a chatter.
 *
 * Only 4xx bodies carry text meant for the end user. A 5xx body is a server
 * exception (stack-adjacent strings, connection errors, driver noise) and must
 * never be rendered in chat, so those collapse to the caller's fallback.
 */
/**
 * A duration in words. Mirrors ChatModerationService::humanizeSeconds so the
 * moderator's notice and the target's own notice read the same way.
 */
export function humanizeSeconds(seconds) {
    const plural = (value, unit) => `${value} ${unit}${value === 1 ? '' : 's'}`

    if (seconds < 60) return plural(seconds, 'second')
    if (seconds < 3600) return plural(Math.floor(seconds / 60), 'minute')
    if (seconds < 86400) return plural(Math.floor(seconds / 3600), 'hour')

    return plural(Math.floor(seconds / 86400), 'day')
}

export function friendlyError(e, fallback) {
    const status = e?.response?.status

    if (!status) return 'You appear to be offline.'
    if (status >= 500) return fallback
    if (status === 419) return 'Your session expired. Reload the page.'
    if (status === 401 || status === 403) {
        const data = e.response?.data ?? {}

        return data.message || data.error || 'You are not allowed to do that.'
    }

    const data = e.response?.data ?? {}

    return data.message || data.error || fallback
}

/**
 * Live chat state for one source: history, realtime updates, sending and moderation.
 *
 * Reverb is the source of truth for everything that arrives after page load; the
 * HTTP responses only confirm an action, they never insert a second copy of a
 * message (dedupe is on message id).
 */
export function useChat({ sourceId, initialMessages = [], initialSettings = {}, initialState = {} }) {
    const page = usePage()

    const messages = ref([...initialMessages])
    const settings = ref({ ...initialSettings })
    const limits = ref({ ...(initialState.limits ?? {}) })
    const selfTimeout = ref(initialState.timeout ?? null)
    const selfBan = ref(initialState.ban ?? null)

    const hasMore = ref(true)
    const loadingOlder = ref(false)
    const sending = ref(false)
    const error = ref('')

    const bufferSize = page.props.chat?.config?.bufferSize ?? 300
    const seen = new Set(initialMessages.map((message) => message.id))

    const me = computed(() => page.props.auth?.user ?? null)
    const permissions = computed(() => page.props.chat?.permissions ?? {})
    const canModerate = computed(() => !!permissions.value.moderate)

    /** Recent distinct chatters, used for @mention autocomplete. */
    const chatters = computed(() => {
        const byName = new Map()

        for (let i = messages.value.length - 1; i >= 0; i -= 1) {
            const user = messages.value[i].user

            if (user?.name && !byName.has(user.name)) {
                byName.set(user.name, user)
            }
        }

        return [...byName.values()]
    })

    const isSilenced = computed(() => !!selfBan.value || !!selfTimeout.value)

    function push(message) {
        if (message.id !== undefined && seen.has(message.id)) return

        if (message.id !== undefined) seen.add(message.id)

        messages.value.push(message)

        if (messages.value.length > bufferSize) {
            const dropped = messages.value.splice(0, messages.value.length - bufferSize)
            dropped.forEach((message) => seen.delete(message.id))
        }
    }

    /** Add a client-only line (errors, confirmations, moderation notices). */
    function notice(body, level = 'info') {
        push({
            id: `local_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
            type: 'notice',
            level,
            body,
            time: new Date().toTimeString().slice(0, 5),
            local: true,
        })
    }

    function removeLocally(ids) {
        const removing = new Set(ids)
        messages.value = messages.value.filter((message) => !removing.has(message.id))
        ids.forEach((id) => seen.delete(id))
    }

    async function send(text, { replyToId = null } = {}) {
        const body = text.trim()

        if (!body || sending.value) return false

        sending.value = true
        error.value = ''

        try {
            const response = await window.axios.post(route('message.send'), {
                message: body,
                source_id: sourceId,
                reply_to_id: replyToId,
            })

            push(response.data.message)
            limits.value = response.data.limits ?? limits.value

            return true
        } catch (e) {
            const data = e.response?.data ?? {}

            error.value = friendlyError(e, 'Message could not be sent.')

            if (data.limits) limits.value = data.limits
            if (data.timeout) selfTimeout.value = { seconds_remaining: data.timeout.remaining_seconds, reason: data.timeout.reason }

            return false
        } finally {
            sending.value = false
        }
    }

    async function runCommand(input) {
        sending.value = true
        error.value = ''

        try {
            const response = await window.axios.post('/api/command/execute', {
                command: input,
                source_id: sourceId,
            })

            if (response.data?.message) notice(response.data.message, 'success')

            return true
        } catch (e) {
            error.value = friendlyError(e, 'Command failed.')

            return false
        } finally {
            sending.value = false
        }
    }

    async function loadOlder() {
        if (loadingOlder.value || !hasMore.value) return []

        loadingOlder.value = true

        try {
            const oldest = messages.value.find((message) => typeof message.id === 'number')

            const response = await window.axios.get(route('messages.older'), {
                params: { before_id: oldest?.id, source_id: sourceId },
            })

            const older = (response.data.messages ?? []).filter((message) => !seen.has(message.id))

            older.forEach((message) => seen.add(message.id))
            messages.value = [...older, ...messages.value]
            hasMore.value = response.data.hasMore && older.length > 0

            return older
        } catch {
            hasMore.value = false

            return []
        } finally {
            loadingOlder.value = false
        }
    }

    /** Moderation calls. Each resolves to a human-readable result message. */
    const moderation = {
        async deleteMessage(id) {
            const { data } = await window.axios.delete(`/messages/${id}`)
            removeLocally([id])

            return data
        },
        timeout: (userId, seconds, reason = null) =>
            post(route('chat.moderation.timeout'), { user_id: userId, seconds, reason }),
        untimeout: (userId) => post(route('chat.moderation.untimeout'), { user_id: userId }),
        ban: (userId, reason = null, seconds = null) =>
            post(route('chat.moderation.ban'), { user_id: userId, reason, seconds }),
        unban: (userId) => post(route('chat.moderation.unban'), { user_id: userId }),
        purge: (userId, withinSeconds = null) =>
            post(route('chat.moderation.purge'), { user_id: userId, within_seconds: withinSeconds }),
        clear: () => post(route('chat.moderation.clear'), {}),
        announce: (message) => post(route('chat.moderation.announce'), { message }),
        updateSettings: async (changes) => {
            const data = await post(route('chat.moderation.settings'), changes)
            settings.value = data.settings ?? settings.value

            return data
        },
        list: async () => (await window.axios.get(route('chat.moderation.index'))).data,
        userCard: async (userId) =>
            (await window.axios.get(route('chat.users.show', userId), { params: { source_id: sourceId } })).data,
    }

    async function post(url, payload) {
        const { data } = await window.axios.post(url, { ...payload, source_id: sourceId })

        return data
    }

    const joined = []

    function join(kind, name) {
        subscriptions.set(name, (subscriptions.get(name) ?? 0) + 1)
        joined.push(name)

        return kind === 'private' ? window.Echo.private(name) : window.Echo.channel(name)
    }

    function leaveAll() {
        joined.forEach((name) => {
            const remaining = (subscriptions.get(name) ?? 1) - 1

            if (remaining <= 0) {
                subscriptions.delete(name)
                window.Echo.leave(name)
            } else {
                subscriptions.set(name, remaining)
            }
        })

        joined.length = 0
    }

    onMounted(() => {
        const channel = join('channel', `chat.source.${sourceId}`)

        channel
            .listen('.message', (event) => push(event))
            .listen('.notice', (event) => push(event))
            .listen('.messages.deleted', (event) => {
                const removed = event.ids.filter((id) => seen.has(id))
                removeLocally(event.ids)

                // The deletion itself has to reach everyone so the messages disappear,
                // but only moderators are told whose messages they were.
                if (canModerate.value && removed.length > 0 && event.target_name) {
                    notice(
                        `${removed.length} message${removed.length === 1 ? '' : 's'} from ${event.target_name} removed by a moderator`,
                        'warning',
                    )
                }
            })
            .listen('.settings.updated', (event) => {
                settings.value = event.settings

                if (limits.value) {
                    limits.value = {
                        ...limits.value,
                        slow_mode_seconds: event.settings.slow_mode_seconds,
                    }
                }
            })

        if (canModerate.value) {
            join('private', `chat.source.${sourceId}.mods`).listen('.notice', (event) => push(event))
        }

        if (me.value) {
            join('private', `user.${me.value.id}`)
                .listen('.chat.state', (event) => {
                    if (event.state === 'timed_out') {
                        selfTimeout.value = { seconds_remaining: event.seconds_remaining, reason: event.reason }
                        notice(
                            `You were timed out for ${humanizeSeconds(event.seconds_remaining)}${event.reason ? ` (${event.reason})` : ''}`,
                            'error',
                        )
                    } else if (event.state === 'banned') {
                        selfBan.value = { permanent: event.seconds_remaining === null, reason: event.reason }
                        notice(`You were banned from chat${event.reason ? ` (${event.reason})` : ''}`, 'error')
                    } else {
                        selfTimeout.value = null
                        selfBan.value = null
                        notice('Your chat restrictions were lifted', 'success')
                    }
                })
                .listen('.command.feedback', (event) => notice(event.message, event.type))
        }
    })

    onUnmounted(() => {
        leaveAll()
    })

    return {
        messages,
        settings,
        limits,
        selfTimeout,
        selfBan,
        isSilenced,
        chatters,
        hasMore,
        loadingOlder,
        sending,
        error,
        me,
        permissions,
        canModerate,
        send,
        runCommand,
        loadOlder,
        notice,
        removeLocally,
        moderation,
    }
}
