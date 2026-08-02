<script setup>
import { computed, ref } from 'vue'
import ChatBadges from '@/Components/Chat/ChatBadges.vue'
import { humanizeSeconds } from '@/composables/useChat'

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
    })
})

const punishment = computed(() => {
    if (props.card?.ban) {
        return props.card.ban.permanent
            ? 'Banned permanently'
            : `Banned until ${new Date(props.card.ban.expires_at).toLocaleString()}`
    }

    if (props.card?.timeout) {
        return `Timed out · ${humanizeSeconds(props.card.timeout.seconds_remaining)} left`
    }

    return null
})

const showModeration = computed(() => props.card?.can_moderate && !props.card?.is_self)

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
    <div class="border-b border-primary-700 bg-primary-950/98 shadow-xl backdrop-blur-sm" @keydown.esc="emit('close')">
        <div v-if="loading || !card" class="px-3 py-4 text-center text-xs text-primary-400">Loading…</div>

        <template v-else>
            <!-- Identity -->
            <div class="flex items-start justify-between gap-2 px-3 py-2">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <ChatBadges :badges="card.badges ?? []" />
                        <span class="truncate text-sm font-semibold" :style="{ color: card.color }">{{ card.name }}</span>
                    </div>
                    <div class="mt-0.5 flex flex-wrap gap-x-2 text-[11px] text-primary-500">
                        <span v-if="memberSince">Since {{ memberSince }}</span>
                        <span v-if="card.message_count !== undefined">{{ card.message_count }} messages</span>
                        <span v-if="punishment" class="font-medium text-amber-300">{{ punishment }}</span>
                    </div>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded p-1 text-primary-400 hover:bg-primary-800 hover:text-primary-100"
                    title="Close"
                    @click="emit('close')"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            <div class="max-h-[17rem] space-y-2.5 overflow-y-auto overscroll-contain px-3 pb-3">
                <button
                    type="button"
                    class="w-full rounded-md bg-primary-800 px-3 py-1.5 text-xs text-primary-100 hover:bg-primary-700"
                    @click="emit('mention', card)"
                >
                    Mention @{{ card.name }}
                </button>

                <!-- Recent messages, moderators only -->
                <section v-if="card.recent_messages?.length">
                    <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">Recent messages</div>
                    <div class="max-h-24 overflow-y-auto rounded-md bg-primary-900/60 px-2 py-1">
                        <div v-for="message in card.recent_messages" :key="message.id" class="text-[11px] text-primary-300">
                            <span class="mr-1 font-mono text-primary-600">{{ message.time }}</span>{{ message.body }}
                        </div>
                    </div>
                </section>

                <template v-if="showModeration">
                    <input
                        v-model="reason"
                        type="text"
                        maxlength="200"
                        placeholder="Reason (optional)"
                        class="w-full rounded-md border border-primary-800 bg-primary-900 px-2 py-1.5 text-xs text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none"
                    />

                    <section>
                        <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">Timeout</div>
                        <div class="flex gap-1">
                            <button
                                v-for="preset in timeoutPresets"
                                :key="preset.seconds"
                                type="button"
                                :disabled="busy"
                                class="flex-1 rounded border border-primary-700 py-1 text-[11px] text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                                @click="run('timeout', { userId: card.id, seconds: preset.seconds })"
                            >
                                {{ preset.label }}
                            </button>
                        </div>
                    </section>

                    <div class="flex flex-wrap gap-1">
                        <button
                            type="button"
                            :disabled="busy"
                            class="rounded border border-primary-700 px-2 py-1 text-[11px] text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                            @click="run('purge', { userId: card.id })"
                        >
                            Delete messages
                        </button>
                        <button
                            v-if="card.timeout"
                            type="button"
                            :disabled="busy"
                            class="rounded border border-primary-700 px-2 py-1 text-[11px] text-primary-200 hover:bg-primary-800 disabled:opacity-50"
                            @click="run('untimeout', { userId: card.id })"
                        >
                            Remove timeout
                        </button>
                        <button
                            v-if="canBan && card.can_ban && !card.ban"
                            type="button"
                            :disabled="busy"
                            class="rounded border border-red-800 px-2 py-1 text-[11px] text-red-300 hover:bg-red-900/40 disabled:opacity-50"
                            @click="run('ban', { userId: card.id })"
                        >
                            Ban
                        </button>
                        <button
                            v-if="canBan && card.ban"
                            type="button"
                            :disabled="busy"
                            class="rounded border border-emerald-800 px-2 py-1 text-[11px] text-emerald-300 hover:bg-emerald-900/40 disabled:opacity-50"
                            @click="run('unban', { userId: card.id })"
                        >
                            Unban
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
