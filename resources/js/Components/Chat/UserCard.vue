<script setup>
import { computed, ref } from 'vue'
import ChatBadges from '@/Components/Chat/ChatBadges.vue'

const props = defineProps({
    card: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    canBan: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'mention', 'timeout', 'untimeout', 'ban', 'unban', 'purge'])

const reason = ref('')
const busy = ref(false)

const timeoutPresets = [
    { label: '1m', seconds: 60 },
    { label: '10m', seconds: 600 },
    { label: '1h', seconds: 3600 },
    { label: '24h', seconds: 86400 },
]

const memberSince = computed(() => {
    if (!props.card?.member_since) return null

    return new Date(props.card.member_since).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
})

const punishment = computed(() => {
    if (props.card?.ban) {
        return props.card.ban.permanent
            ? 'Banned permanently'
            : `Banned until ${new Date(props.card.ban.expires_at).toLocaleString()}`
    }

    if (props.card?.timeout) {
        return `Timed out for ${props.card.timeout.seconds_remaining}s`
    }

    return null
})

async function run(action, payload) {
    busy.value = true

    try {
        emit(action, { ...payload, reason: reason.value || null })
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="emit('close')">
        <div class="w-full max-w-sm overflow-hidden rounded-xl border border-primary-700 bg-primary-950 shadow-2xl">
            <div v-if="loading || !card" class="p-6 text-center text-sm text-primary-400">Loading…</div>

            <template v-else>
                <div class="flex items-start justify-between gap-3 border-b border-primary-800 px-4 py-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <ChatBadges :badges="card.badges ?? []" />
                            <span class="text-base font-semibold" :style="{ color: card.color }">{{ card.name }}</span>
                        </div>
                        <div class="mt-1 space-y-0.5 text-xs text-primary-400">
                            <div v-if="memberSince">Member since {{ memberSince }}</div>
                            <div v-if="card.message_count !== undefined">{{ card.message_count }} messages in this chat</div>
                            <div v-if="punishment" class="font-medium text-amber-300">{{ punishment }}</div>
                        </div>
                    </div>
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

                <div v-if="card.recent_messages?.length" class="max-h-32 overflow-y-auto border-b border-primary-800 px-4 py-2">
                    <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">Recent messages</div>
                    <div v-for="message in card.recent_messages" :key="message.id" class="text-xs text-primary-300">
                        <span class="mr-1 font-mono text-primary-600">{{ message.time }}</span>{{ message.body }}
                    </div>
                </div>

                <div class="space-y-3 px-4 py-3">
                    <button
                        type="button"
                        class="w-full rounded-md bg-primary-800 px-3 py-2 text-sm text-primary-100 hover:bg-primary-700"
                        @click="emit('mention', card)"
                    >
                        Mention @{{ card.name }}
                    </button>

                    <template v-if="card.can_moderate && !card.is_self">
                        <input
                            v-model="reason"
                            type="text"
                            maxlength="200"
                            placeholder="Reason (optional)"
                            class="w-full rounded-md border border-primary-800 bg-primary-900 px-3 py-2 text-sm text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none"
                        />

                        <div>
                            <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">Timeout</div>
                            <div class="grid grid-cols-4 gap-1.5">
                                <button
                                    v-for="preset in timeoutPresets"
                                    :key="preset.seconds"
                                    type="button"
                                    :disabled="busy"
                                    class="rounded-md border border-primary-700 py-1.5 text-xs text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                                    @click="run('timeout', { userId: card.id, seconds: preset.seconds })"
                                >
                                    {{ preset.label }}
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                type="button"
                                :disabled="busy"
                                class="rounded-md border border-primary-700 py-1.5 text-xs text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                                @click="run('purge', { userId: card.id })"
                            >
                                Delete messages
                            </button>
                            <button
                                v-if="card.timeout"
                                type="button"
                                :disabled="busy"
                                class="rounded-md border border-primary-700 py-1.5 text-xs text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                                @click="run('untimeout', { userId: card.id })"
                            >
                                Remove timeout
                            </button>
                            <button
                                v-if="canBan && card.can_ban && !card.ban"
                                type="button"
                                :disabled="busy"
                                class="rounded-md border border-red-800 py-1.5 text-xs text-red-300 hover:bg-red-900/40 disabled:opacity-50"
                                @click="run('ban', { userId: card.id })"
                            >
                                Ban
                            </button>
                            <button
                                v-if="canBan && card.ban"
                                type="button"
                                :disabled="busy"
                                class="rounded-md border border-emerald-800 py-1.5 text-xs text-emerald-300 hover:bg-emerald-900/40 disabled:opacity-50"
                                @click="run('unban', { userId: card.id })"
                            >
                                Unban
                            </button>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</template>
