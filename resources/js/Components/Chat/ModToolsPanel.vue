<script setup>
import { onMounted, ref } from 'vue'

const props = defineProps({
    settings: { type: Object, required: true },
    moderation: { type: Object, required: true },
    canBan: { type: Boolean, default: false },
    canAnnounce: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'result'])

const slowPresets = [0, 3, 10, 30, 60, 120]
const announcement = ref('')
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
}

async function lift(entry, type) {
    await guard(() => (type === 'ban' ? props.moderation.unban(entry.user_id) : props.moderation.untimeout(entry.user_id)))
    await refresh()
}

onMounted(refresh)
</script>

<template>
    <div class="flex h-full flex-col bg-primary-950">
        <div class="flex items-center justify-between border-b border-primary-800 px-3 py-2">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-primary-100">Mod tools</h2>
            <button
                type="button"
                class="rounded p-1 text-primary-400 hover:bg-primary-800 hover:text-primary-100"
                @click="emit('close')"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>

        <div class="flex-1 space-y-5 overflow-y-auto px-3 py-3">
            <section>
                <h3 class="mb-2 text-[10px] uppercase tracking-widest text-primary-500">Slow mode</h3>
                <div class="grid grid-cols-6 gap-1">
                    <button
                        v-for="seconds in slowPresets"
                        :key="seconds"
                        type="button"
                        :disabled="busy"
                        class="rounded-md border py-1.5 text-xs transition-colors disabled:opacity-50"
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

            <section>
                <h3 class="mb-2 text-[10px] uppercase tracking-widest text-primary-500">Chat modes</h3>
                <div class="space-y-1.5">
                    <button
                        type="button"
                        :disabled="busy"
                        class="flex w-full items-center justify-between rounded-md border border-primary-700 px-3 py-2 text-xs text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                        @click="toggle('emote_only')"
                    >
                        <span>Emote-only chat</span>
                        <span :class="settings.emote_only ? 'text-emerald-300' : 'text-primary-500'">
                            {{ settings.emote_only ? 'On' : 'Off' }}
                        </span>
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        class="flex w-full items-center justify-between rounded-md border border-primary-700 px-3 py-2 text-xs text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                        @click="toggle('sponsors_only')"
                    >
                        <span>Sponsors-only chat</span>
                        <span :class="settings.sponsors_only ? 'text-emerald-300' : 'text-primary-500'">
                            {{ settings.sponsors_only ? 'On' : 'Off' }}
                        </span>
                    </button>
                </div>
            </section>

            <section v-if="canAnnounce">
                <h3 class="mb-2 text-[10px] uppercase tracking-widest text-primary-500">Announcement</h3>
                <textarea
                    v-model="announcement"
                    rows="2"
                    maxlength="500"
                    placeholder="Pinned to the top of everyone's chat"
                    class="w-full resize-none rounded-md border border-primary-800 bg-primary-900 px-3 py-2 text-sm text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none"
                />
                <button
                    type="button"
                    :disabled="busy || !announcement.trim()"
                    class="mt-1.5 w-full rounded-md bg-primary-600 py-2 text-xs font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                    @click="sendAnnouncement"
                >
                    Send announcement
                </button>
            </section>

            <section>
                <h3 class="mb-2 text-[10px] uppercase tracking-widest text-primary-500">Danger zone</h3>
                <button
                    type="button"
                    :disabled="busy"
                    class="w-full rounded-md border border-red-800 py-2 text-xs text-red-300 hover:bg-red-900/40 disabled:opacity-50"
                    @click="clearChat"
                    @blur="confirmingClear = false"
                >
                    {{ confirmingClear ? 'Click again to clear every message' : 'Clear chat' }}
                </button>
            </section>

            <section>
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-[10px] uppercase tracking-widest text-primary-500">Active punishments</h3>
                    <button type="button" class="text-[10px] text-primary-400 hover:text-primary-200" @click="refresh">
                        Refresh
                    </button>
                </div>

                <div v-if="!active.timeouts.length && !active.bans.length" class="text-xs text-primary-500">
                    Nobody is timed out or banned.
                </div>

                <div v-for="entry in active.timeouts" :key="`t-${entry.user_id}`" class="flex items-center justify-between py-1 text-xs">
                    <span class="truncate text-primary-200">
                        {{ entry.name }}
                        <span class="text-primary-500">· {{ entry.seconds_remaining }}s left</span>
                    </span>
                    <button type="button" class="text-primary-400 hover:text-primary-100" @click="lift(entry, 'timeout')">
                        Lift
                    </button>
                </div>

                <div v-for="entry in active.bans" :key="`b-${entry.user_id}`" class="flex items-center justify-between py-1 text-xs">
                    <span class="truncate text-red-300">
                        {{ entry.name }}
                        <span class="text-primary-500">· {{ entry.permanent ? 'permanent' : 'temporary' }}</span>
                    </span>
                    <button
                        v-if="canBan"
                        type="button"
                        class="text-primary-400 hover:text-primary-100"
                        @click="lift(entry, 'ban')"
                    >
                        Unban
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
