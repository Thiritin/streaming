<script setup>
import { computed, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { humanizeSeconds } from '@/composables/useChat'

const props = defineProps({
    settings: { type: Object, required: true },
    moderation: { type: Object, required: true },
    canBan: { type: Boolean, default: false },
    canAnnounce: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'result'])

const page = usePage()
const emotesEnabled = computed(() => page.props.features?.emotes !== false)

const slowPresets = [0, 3, 10, 30, 60, 120]
const announcement = ref('')
const composing = ref(false)
const active = ref({ timeouts: [], bans: [] })
const busy = ref(false)
const confirmingClear = ref(false)

async function refresh() {
    try {
        active.value = await props.moderation.list()
    } catch {
        emit('result', { message: 'Could not load active punishments.', level: 'error' })
    }
}

async function guard(action, successLevel = 'success') {
    busy.value = true

    try {
        const data = await action()

        if (data?.message) emit('result', { message: data.message, level: successLevel })

        return data
    } catch (e) {
        emit('result', {
            message: e.response?.data?.message || e.response?.data?.error || 'Action failed.',
            level: 'error',
        })
    } finally {
        busy.value = false
    }
}

async function setSlowMode(seconds) {
    await guard(() => props.moderation.updateSettings({ slow_mode_seconds: seconds }))
}

async function toggle(key) {
    await guard(() => props.moderation.updateSettings({ [key]: !props.settings[key] }))
}

async function clearChat() {
    if (!confirmingClear.value) {
        confirmingClear.value = true

        return
    }

    confirmingClear.value = false
    await guard(() => props.moderation.clear(), 'warning')
}

async function sendAnnouncement() {
    if (!announcement.value.trim()) return

    await guard(() => props.moderation.announce(announcement.value.trim()))
    announcement.value = ''
    composing.value = false
}

async function lift(entry, type) {
    await guard(() => (type === 'ban' ? props.moderation.unban(entry.user_id) : props.moderation.untimeout(entry.user_id)))
    await refresh()
}

onMounted(refresh)
</script>

<template>
    <div class="border-b border-primary-700 bg-primary-950/98 shadow-xl backdrop-blur-sm">
        <div class="flex items-center justify-between px-3 py-2">
            <div class="flex items-center gap-1.5 text-primary-100">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v6c0 4-3 7.5-7 9-4-1.5-7-5-7-9V6l7-3z" />
                </svg>
                <h2 class="text-xs font-semibold uppercase tracking-wider">Mod tools</h2>
            </div>
            <button
                type="button"
                class="rounded p-1 text-primary-400 hover:bg-primary-800 hover:text-primary-100"
                title="Close"
                @click="emit('close')"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>

        <div class="max-h-[19rem] space-y-3 overflow-y-auto overscroll-contain px-3 pb-3">
            <!-- Slow mode -->
            <section>
                <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">Slow mode</div>
                <div class="flex gap-1">
                    <button
                        v-for="seconds in slowPresets"
                        :key="seconds"
                        type="button"
                        :disabled="busy"
                        class="flex-1 rounded border py-1 text-[11px] transition-colors disabled:opacity-50"
                        :class="
                            settings.slow_mode_seconds === seconds
                                ? 'border-primary-400 bg-primary-400/20 text-primary-100'
                                : 'border-primary-700 text-primary-300 hover:bg-primary-800'
                        "
                        @click="setSlowMode(seconds)"
                    >
                        {{ seconds === 0 ? 'Off' : `${seconds}s` }}
                    </button>
                </div>
            </section>

            <!-- Modes + actions -->
            <section>
                <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">Chat modes</div>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-if="emotesEnabled"
                        type="button"
                        :disabled="busy"
                        class="rounded border px-2 py-1 text-[11px] transition-colors disabled:opacity-50"
                        :class="
                            settings.emote_only
                                ? 'border-emerald-500 bg-emerald-500/15 text-emerald-300'
                                : 'border-primary-700 text-primary-300 hover:bg-primary-800'
                        "
                        @click="toggle('emote_only')"
                    >
                        Emote-only
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        class="rounded border px-2 py-1 text-[11px] transition-colors disabled:opacity-50"
                        :class="
                            settings.sponsors_only
                                ? 'border-emerald-500 bg-emerald-500/15 text-emerald-300'
                                : 'border-primary-700 text-primary-300 hover:bg-primary-800'
                        "
                        @click="toggle('sponsors_only')"
                    >
                        Sponsors-only
                    </button>
                    <button
                        v-if="canAnnounce"
                        type="button"
                        class="rounded border px-2 py-1 text-[11px] transition-colors"
                        :class="
                            composing
                                ? 'border-primary-400 bg-primary-400/20 text-primary-100'
                                : 'border-primary-700 text-primary-300 hover:bg-primary-800'
                        "
                        @click="composing = !composing"
                    >
                        Announce
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        class="rounded border border-red-800 px-2 py-1 text-[11px] text-red-300 transition-colors hover:bg-red-900/40 disabled:opacity-50"
                        @click="clearChat"
                        @blur="confirmingClear = false"
                    >
                        {{ confirmingClear ? 'Confirm clear?' : 'Clear chat' }}
                    </button>
                </div>
            </section>

            <!-- Announcement composer -->
            <section v-if="canAnnounce && composing">
                <textarea
                    v-model="announcement"
                    rows="2"
                    maxlength="500"
                    autofocus
                    placeholder="Pinned to the top of everyone's chat"
                    class="w-full resize-none rounded-md border border-primary-800 bg-primary-900 px-2 py-1.5 text-xs text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none"
                    @keydown.enter.exact.prevent="sendAnnouncement"
                    @keydown.esc="composing = false"
                />
                <button
                    type="button"
                    :disabled="busy || !announcement.trim()"
                    class="mt-1 w-full rounded-md bg-primary-600 py-1.5 text-[11px] font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                    @click="sendAnnouncement"
                >
                    Send announcement
                </button>
            </section>

            <!-- Active punishments -->
            <section>
                <div class="mb-1 flex items-center justify-between">
                    <span class="text-[10px] uppercase tracking-widest text-primary-500">
                        Active
                        <template v-if="active.timeouts.length || active.bans.length">
                            ({{ active.timeouts.length + active.bans.length }})
                        </template>
                    </span>
                    <button type="button" class="text-[10px] text-primary-400 hover:text-primary-200" @click="refresh">
                        Refresh
                    </button>
                </div>

                <p v-if="!active.timeouts.length && !active.bans.length" class="text-[11px] text-primary-600">
                    Nobody is timed out or banned.
                </p>

                <div
                    v-for="entry in active.timeouts"
                    :key="`t-${entry.user_id}`"
                    class="flex items-center justify-between gap-2 py-0.5 text-[11px]"
                >
                    <span class="truncate text-primary-200">
                        {{ entry.name }}
                        <span class="text-primary-500">· {{ humanizeSeconds(entry.seconds_remaining) }} left</span>
                    </span>
                    <button type="button" class="shrink-0 text-primary-400 hover:text-primary-100" @click="lift(entry, 'timeout')">
                        Lift
                    </button>
                </div>

                <div
                    v-for="entry in active.bans"
                    :key="`b-${entry.user_id}`"
                    class="flex items-center justify-between gap-2 py-0.5 text-[11px]"
                >
                    <span class="truncate text-red-300">
                        {{ entry.name }}
                        <span class="text-primary-500">· {{ entry.permanent ? 'permanent' : 'temporary' }}</span>
                    </span>
                    <button
                        v-if="canBan"
                        type="button"
                        class="shrink-0 text-primary-400 hover:text-primary-100"
                        @click="lift(entry, 'ban')"
                    >
                        Unban
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
