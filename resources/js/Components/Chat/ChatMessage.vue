<script setup>
import { computed } from 'vue'
import ChatBadges from '@/Components/Chat/ChatBadges.vue'
import { isEmoteOnly, mentionsUser, tokenize } from '@/composables/useChatTokens'

const props = defineProps({
    message: { type: Object, required: true },
    emotes: { type: Object, default: () => ({}) },
    allowedDomains: { type: Array, default: () => [] },
    currentUser: { type: String, default: null },
    showTimestamps: { type: Boolean, default: true },
    canModerate: { type: Boolean, default: false },
    canBan: { type: Boolean, default: false },
    isOwn: { type: Boolean, default: false },
})

const emit = defineEmits(['reply', 'open-user', 'delete', 'timeout', 'ban'])

const tokens = computed(() =>
    tokenize(props.message.body ?? '', {
        emotes: props.emotes,
        currentUser: props.currentUser,
        allowedDomains: props.allowedDomains,
    }),
)

const highlighted = computed(() => mentionsUser(tokens.value))
const bigEmotes = computed(() => isEmoteOnly(tokens.value))
const author = computed(() => props.message.user ?? null)
const canDelete = computed(() => props.canModerate || props.isOwn)
const showActions = computed(() => props.message.type === 'user' && (canDelete.value || props.canModerate))

const noticeClasses = {
    success: 'text-emerald-300',
    warning: 'text-amber-300',
    error: 'text-red-300',
    info: 'text-primary-300',
}
</script>

<template>
    <!-- Inline notice: moderation events, command feedback, client-side errors -->
    <div
        v-if="message.type === 'notice' || message.type === 'system'"
        class="px-3 py-1.5 text-xs"
        :class="noticeClasses[message.level] ?? noticeClasses.info"
    >
        <span class="mr-1 opacity-60">•</span>{{ message.body }}
    </div>

    <!-- Announcement -->
    <div v-else-if="message.type === 'announcement'" class="px-2 py-1">
        <div class="rounded-md border-l-4 border-amber-400 bg-amber-400/10 px-3 py-2">
            <div class="mb-1 text-[10px] font-bold uppercase tracking-widest text-amber-300">Announcement</div>
            <div class="break-words text-sm text-primary-50">
                <template v-for="(token, index) in tokens" :key="index">
                    <span v-if="token.type === 'text'">{{ token.value }}</span>
                    <img
                        v-else-if="token.type === 'emote'"
                        :src="token.url"
                        :alt="`:${token.name}:`"
                        :title="`:${token.name}:`"
                        class="mx-0.5 inline-block h-6 w-6 align-middle"
                        loading="lazy"
                    />
                    <a
                        v-else-if="token.type === 'link'"
                        :href="token.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline decoration-dotted"
                        >{{ token.label }}</a
                    >
                    <span v-else class="font-semibold">@{{ token.name }}</span>
                </template>
            </div>
        </div>
    </div>

    <!-- Regular message -->
    <div
        v-else
        class="group relative px-3 py-[3px] text-sm leading-snug transition-colors hover:bg-primary-900/70"
        :class="highlighted ? 'bg-primary-500/15 shadow-[inset_3px_0_0_0_var(--color-primary-300)]' : ''"
    >
        <!-- Quoted parent when this is a reply -->
        <div v-if="message.reply_to" class="mb-0.5 flex items-center gap-1 pl-1 text-[11px] text-primary-400">
            <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9l5-5" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 9h11a5 5 0 015 5v6" />
            </svg>
            <span class="truncate">
                <span class="font-medium">{{ message.reply_to.name }}</span>: {{ message.reply_to.body }}
            </span>
        </div>

        <span v-if="showTimestamps" class="mr-1.5 select-none font-mono text-[11px] text-primary-500">
            {{ message.time }}
        </span>

        <ChatBadges :badges="message.badges ?? []" class="mr-1" />

        <button
            type="button"
            class="cursor-pointer font-semibold hover:underline"
            :style="{ color: message.color }"
            @click="emit('open-user', message)"
        >
            {{ message.name }}
        </button>
        <span class="mr-1 text-primary-400">:</span>

        <span class="break-words text-primary-100" :class="bigEmotes ? 'align-middle' : ''">
            <template v-for="(token, index) in tokens" :key="index">
                <span v-if="token.type === 'text'">{{ token.value }}</span>
                <img
                    v-else-if="token.type === 'emote'"
                    :src="token.url"
                    :alt="`:${token.name}:`"
                    :title="`:${token.name}:`"
                    class="mx-0.5 inline-block align-middle"
                    :class="bigEmotes ? 'h-12 w-12' : 'h-7 w-7'"
                    loading="lazy"
                />
                <a
                    v-else-if="token.type === 'link'"
                    :href="token.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary-200 underline decoration-dotted hover:text-primary-100"
                    >{{ token.label }}</a
                >
                <span
                    v-else
                    class="rounded px-1 font-semibold"
                    :class="token.self ? 'bg-primary-400 text-primary-950' : 'text-primary-200'"
                    >@{{ token.name }}</span
                >
            </template>
        </span>

        <!-- Hover quick actions -->
        <div
            v-if="showActions"
            class="absolute right-1 top-0.5 hidden items-center gap-0.5 rounded-md border border-primary-700 bg-primary-950/95 px-1 py-0.5 shadow-lg group-hover:flex"
        >
            <button
                type="button"
                title="Reply"
                class="rounded p-1 text-primary-300 hover:bg-primary-800 hover:text-primary-100"
                @click="emit('reply', message)"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9l5-5" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 9h11a5 5 0 015 5v6" />
                </svg>
            </button>
            <button
                v-if="canDelete"
                type="button"
                title="Delete message"
                class="rounded p-1 text-primary-300 hover:bg-primary-800 hover:text-red-300"
                @click="emit('delete', message)"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M10 11v6M14 11v6M5 7l1 12h12l1-12" />
                </svg>
            </button>
            <button
                v-if="canModerate && author"
                type="button"
                title="Timeout 10 minutes"
                class="rounded p-1 text-primary-300 hover:bg-primary-800 hover:text-amber-300"
                @click="emit('timeout', { message, seconds: 600 })"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linecap="round" d="M12 7v5l3 2" />
                </svg>
            </button>
            <button
                v-if="canBan && author"
                type="button"
                title="Ban from chat"
                class="rounded p-1 text-primary-300 hover:bg-primary-800 hover:text-red-400"
                @click="emit('ban', message)"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linecap="round" d="M5.6 5.6l12.8 12.8" />
                </svg>
            </button>
        </div>
    </div>
</template>
