<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import ChatInput from '@/Components/Chat/ChatInput.vue'
import ChatMessage from '@/Components/Chat/ChatMessage.vue'
import ModToolsPanel from '@/Components/Chat/ModToolsPanel.vue'
import UserCard from '@/Components/Chat/UserCard.vue'
import { useChat } from '@/composables/useChat'

const props = defineProps({
    sourceId: { type: [Number, String], required: true },
    chatMessages: { type: Array, default: () => [] },
    chatSettings: { type: Object, default: () => ({}) },
    chatState: { type: Object, default: () => ({}) },
    showHeader: { type: Boolean, default: true },
    // Laid over the player rather than beside it: keep the video readable behind
    // the messages instead of painting the panel's own background over it.
    transparent: { type: Boolean, default: false },
})

const page = usePage()

const chat = useChat({
    sourceId: props.sourceId,
    initialMessages: props.chatMessages,
    initialSettings: props.chatSettings,
    initialState: props.chatState,
})

const scroller = ref(null)
const input = ref(null)
const draft = ref('')
const replyTo = ref(null)
const pinned = ref(true)
const unread = ref(0)
const showModTools = ref(false)
const showTimestamps = ref(true)
const userCard = ref(null)
const loadingCard = ref(false)

const emotes = computed(() => page.props.chat?.emotes?.map ?? {})
const allowedDomains = computed(() => page.props.chat?.config?.allowedDomains ?? [])
const currentUserName = computed(() => chat.me.value?.name ?? null)

// Only possible where login is optional: guests read the log over the public
// channel but get a sign-in prompt where the composer would be.
const signedIn = computed(() => !!page.props.auth?.user)
const loginUrl = computed(() => page.props.features?.loginUrl ?? '/login')

const activeModes = computed(() => {
    const modes = []

    if (chat.settings.value.slow_mode_seconds > 0) modes.push(`Slow ${chat.settings.value.slow_mode_seconds}s`)
    if (chat.settings.value.emote_only) modes.push('Emote-only')
    if (chat.settings.value.sponsors_only) modes.push('Sponsors-only')

    return modes
})

const silenced = computed(() => {
    if (chat.selfBan.value) {
        return {
            type: 'ban',
            message: chat.selfBan.value.permanent
                ? 'You are banned from chat.'
                : 'You are temporarily banned from chat.',
        }
    }

    if (chat.selfTimeout.value) {
        const reason = chat.selfTimeout.value.reason

        return {
            type: 'timeout',
            message: `You are timed out${reason ? `: ${reason}` : '.'}`,
        }
    }

    return null
})

function scrollToBottom(smooth = false) {
    const element = scroller.value

    if (!element) return

    element.scrollTo({ top: element.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
    pinned.value = true
    unread.value = 0
}

function onScroll() {
    const element = scroller.value

    if (!element) return

    const distanceFromBottom = element.scrollHeight - element.scrollTop - element.clientHeight

    pinned.value = distanceFromBottom < 60

    if (pinned.value) unread.value = 0

    if (element.scrollTop < 200) void loadOlder()
}

async function loadOlder() {
    const element = scroller.value
    const previousHeight = element?.scrollHeight ?? 0

    const older = await chat.loadOlder()

    if (older.length === 0) return

    await nextTick()

    if (element) {
        // Keep the reading position anchored while content is prepended.
        element.scrollTop += element.scrollHeight - previousHeight
    }
}

watch(
    () => chat.messages.value.length,
    async () => {
        if (pinned.value) {
            await nextTick()
            scrollToBottom()
        } else {
            unread.value += 1
        }
    },
)

onMounted(() => nextTick(() => scrollToBottom()))

async function send() {
    const text = draft.value.trim()

    if (!text) return

    const ok = text.startsWith('/')
        ? await chat.runCommand(text)
        : await chat.send(text, { replyToId: replyTo.value?.id ?? null })

    if (ok) {
        draft.value = ''
        replyTo.value = null
        scrollToBottom(true)
    }
}

function startReply(message) {
    replyTo.value = { id: message.id, name: message.name, body: message.body }
    input.value?.focus()
}

function mention(user) {
    input.value?.insert(`@${user.name} `)
    userCard.value = null
}

async function openUserCard(message) {
    if (!message.user?.id) return

    loadingCard.value = true
    userCard.value = { id: message.user.id, name: message.name, color: message.color, badges: message.badges }

    try {
        userCard.value = await chat.moderation.userCard(message.user.id)
    } catch {
        chat.notice('Could not load that user.', 'error')
        userCard.value = null
    } finally {
        loadingCard.value = false
    }
}

async function act(action, payload = {}) {
    try {
        const data = await action()

        if (data?.message) chat.notice(data.message, 'success')
    } catch (e) {
        chat.notice(e.response?.data?.message || e.response?.data?.error || 'Action failed.', 'error')
    } finally {
        if (payload.closeCard) userCard.value = null
    }
}

const onDelete = (message) => act(() => chat.moderation.deleteMessage(message.id))
const onTimeout = ({ message, seconds }) => act(() => chat.moderation.timeout(message.user.id, seconds))
const onBan = (message) => act(() => chat.moderation.ban(message.user.id))
</script>

<template>
    <div
        class="relative flex h-full flex-col"
        :class="transparent ? 'bg-primary-950/45 backdrop-blur-md [text-shadow:0_1px_3px_rgb(0_0_0/0.9)]' : 'bg-primary-950'"
    >
        <div
            v-if="showHeader"
            class="flex items-center justify-between border-b border-primary-800 px-3 py-2.5 text-primary-100"
        >
            <h1 class="text-sm font-semibold uppercase tracking-wider">Stream chat</h1>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    :title="showTimestamps ? 'Hide timestamps' : 'Show timestamps'"
                    class="rounded p-1.5 text-primary-400 hover:bg-primary-800 hover:text-primary-100"
                    @click="showTimestamps = !showTimestamps"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M12 7v5l3 2" />
                    </svg>
                </button>
                <button
                    v-if="chat.canModerate.value"
                    type="button"
                    :title="showModTools ? 'Close mod tools' : 'Mod tools'"
                    class="rounded p-1.5 transition-colors"
                    :class="
                        showModTools
                            ? 'bg-primary-800 text-primary-100'
                            : 'text-primary-400 hover:bg-primary-800 hover:text-primary-100'
                    "
                    @click="showModTools = !showModTools"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 3l7 3v6c0 4-3 7.5-7 9-4-1.5-7-5-7-9V6l7-3z"
                        />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Active chat modes -->
        <div
            v-if="activeModes.length"
            class="flex flex-wrap gap-1 border-b border-primary-800 bg-primary-900/60 px-3 py-1.5"
        >
            <span
                v-for="mode in activeModes"
                :key="mode"
                class="rounded bg-primary-800 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-primary-200"
            >
                {{ mode }}
            </span>
        </div>

        <!-- Message log -->
        <div class="relative min-h-0 flex-1">
            <div ref="scroller" class="absolute inset-0 overflow-y-auto overscroll-contain py-2" @scroll="onScroll">
                <div v-if="chat.loadingOlder.value" class="py-2 text-center text-xs text-primary-500">
                    Loading older messages…
                </div>
                <div v-else-if="!chat.hasMore.value" class="py-2 text-center text-xs text-primary-600">
                    Beginning of chat
                </div>

                <!-- Enter only, and only opacity/transform: a leave or move
                     transition here would fight the autoscroll, and anything
                     that changes height would break the scroll anchoring that
                     `loadOlder` does off scrollHeight. -->
                <TransitionGroup name="msg">
                    <ChatMessage
                        v-for="message in chat.messages.value"
                        :key="message.id"
                        :message="message"
                        :emotes="emotes"
                        :allowed-domains="allowedDomains"
                        :current-user="currentUserName"
                        :show-timestamps="showTimestamps"
                        :can-moderate="chat.canModerate.value"
                        :can-ban="!!chat.permissions.value.ban"
                        :is-own="message.user?.id === chat.me.value?.id"
                        @reply="startReply"
                        @open-user="openUserCard"
                        @delete="onDelete"
                        @timeout="onTimeout"
                        @ban="onBan"
                    />
                </TransitionGroup>
            </div>

            <transition
                enter-active-class="transition duration-(--dur-fast)"
                enter-from-class="translate-y-2 opacity-0"
                leave-active-class="transition duration-(--dur-fast)"
                leave-to-class="translate-y-2 opacity-0"
            >
                <button
                    v-if="!pinned"
                    type="button"
                    class="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-primary-600 px-3 py-1.5 text-xs font-medium text-white shadow-lg hover:bg-primary-500"
                    @click="scrollToBottom(true)"
                >
                    {{ unread > 0 ? `${unread} new message${unread === 1 ? '' : 's'}` : 'Jump to latest' }}
                </button>
            </transition>

            <!-- Mod tools: overlays the top of the chat log, messages stay visible below -->
            <transition
                enter-active-class="transition duration-(--dur-base) ease-(--ease-out-expo)"
                enter-from-class="-translate-y-full opacity-0"
                leave-active-class="transition duration-(--dur-fast) ease-(--ease-in-quart)"
                leave-to-class="-translate-y-full opacity-0"
            >
                <div v-if="showModTools" class="absolute inset-x-0 top-0 z-30">
                    <ModToolsPanel
                        :settings="chat.settings.value"
                        :moderation="chat.moderation"
                        :can-ban="!!chat.permissions.value.ban"
                        :can-announce="!!chat.permissions.value.announce"
                        @close="showModTools = false"
                        @result="({ message, level }) => chat.notice(message, level)"
                    />
                </div>
            </transition>

            <!-- User card: same top overlay, stacked above the mod tools -->
            <transition
                enter-active-class="transition duration-(--dur-base) ease-(--ease-out-expo)"
                enter-from-class="-translate-y-full opacity-0"
                leave-active-class="transition duration-(--dur-fast) ease-(--ease-in-quart)"
                leave-to-class="-translate-y-full opacity-0"
            >
                <div v-if="userCard" class="absolute inset-x-0 top-0 z-40">
                    <UserCard
                        :card="userCard"
                        :loading="loadingCard"
                        :can-ban="!!chat.permissions.value.ban"
                        @close="userCard = null"
                        @mention="mention"
                        @timeout="
                            ({ userId, seconds, reason }) =>
                                act(() => chat.moderation.timeout(userId, seconds, reason), { closeCard: true })
                        "
                        @untimeout="({ userId }) => act(() => chat.moderation.untimeout(userId), { closeCard: true })"
                        @ban="({ userId, reason }) => act(() => chat.moderation.ban(userId, reason), { closeCard: true })"
                        @unban="({ userId }) => act(() => chat.moderation.unban(userId), { closeCard: true })"
                        @purge="({ userId }) => act(() => chat.moderation.purge(userId), { closeCard: true })"
                    />
                </div>
            </transition>
        </div>

        <div
            v-if="!signedIn"
            class="border-t border-primary-800 px-3 py-3 text-center text-sm text-primary-300"
        >
            <a
                :href="loginUrl"
                class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-500"
            >
                Sign in to chat
            </a>
        </div>

        <ChatInput
            v-else
            ref="input"
            v-model="draft"
            :reply-to="replyTo"
            :sending="chat.sending.value"
            :error="chat.error.value"
            :limits="chat.limits.value"
            :settings="chat.settings.value"
            :silenced="silenced"
            :chatters="chat.chatters.value"
            @send="send"
            @cancel-reply="replyTo = null"
        />

    </div>
</template>

<style scoped>
/* Messages arrive one at a time from a socket, so this stays cheap: opacity and
   a 4px lift, no height, nothing that would move the scroll position under
   someone reading. */
.msg-enter-active {
    transition:
        opacity var(--dur-fast) var(--ease-out-quart),
        transform var(--dur-fast) var(--ease-out-quart);
}

.msg-enter-from {
    opacity: 0;
    transform: translateY(4px);
}
</style>
