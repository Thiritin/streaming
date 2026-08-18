<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import EmotePicker from '@/Components/Chat/EmotePicker.vue'

const props = defineProps({
    modelValue: { type: String, default: '' },
    replyTo: { type: Object, default: null },
    sending: { type: Boolean, default: false },
    error: { type: String, default: '' },
    limits: { type: Object, default: () => ({}) },
    settings: { type: Object, default: () => ({}) },
    silenced: { type: Object, default: null },
    chatters: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'send', 'cancel-reply'])

const page = usePage()
const textarea = ref(null)
const showPicker = ref(false)
const highlighted = ref(0)
const cooldown = ref(0)
const dismissed = ref(false)

let cooldownTimer = null

const maxLength = computed(() => page.props.chat?.config?.maxMessageLength ?? 500)
const emotes = computed(() => page.props.chat?.emotes?.list ?? [])
const emotesEnabled = computed(() => page.props.features?.emotes !== false)
const commands = computed(() => page.props.chat?.commands ?? [])
const length = computed(() => props.modelValue.length)
const nearLimit = computed(() => length.value > maxLength.value * 0.8)

const placeholder = computed(() => {
    if (props.silenced?.type === 'ban') return 'You are banned from chat'
    if (props.silenced?.type === 'timeout') return 'You are timed out'
    if (props.settings.emote_only) return 'Emote-only mode: emotes only'
    if (props.settings.sponsors_only) return 'Sponsors-only mode'

    return 'Send a message'
})

const disabled = computed(() => !!props.silenced)

/**
 * The token immediately before the caret decides which suggestion list opens.
 */
const trigger = computed(() => {
    const value = props.modelValue

    if (value.startsWith('/') && !value.includes(' ')) {
        return { kind: 'command', query: value.slice(1).toLowerCase(), start: 0 }
    }

    const emote = value.match(/:([a-z0-9_]{1,20})$/i)
    if (emote) {
        return { kind: 'emote', query: emote[1].toLowerCase(), start: value.length - emote[0].length }
    }

    const mention = value.match(/@([\p{L}\p{N}_.-]{0,32})$/u)
    if (mention) {
        return { kind: 'mention', query: mention[1].toLowerCase(), start: value.length - mention[0].length }
    }

    return null
})

const suggestions = computed(() => {
    const active = trigger.value

    if (!active || dismissed.value) return []

    if (active.kind === 'command') {
        return commands.value
            .filter((command) => command.name.startsWith(active.query))
            .slice(0, 8)
            .map((command) => ({
                key: `c-${command.name}`,
                label: `/${command.name}`,
                hint: command.description,
                insert: `/${command.name} `,
            }))
    }

    if (active.kind === 'emote') {
        return emotes.value
            .filter((emote) => emote.name.includes(active.query))
            .slice(0, 8)
            .map((emote) => ({
                key: `e-${emote.id}`,
                label: `:${emote.name}:`,
                image: emote.url,
                insert: `:${emote.name}: `,
            }))
    }

    return props.chatters
        .filter((user) => user.name?.toLowerCase().includes(active.query))
        .slice(0, 8)
        .map((user) => ({
            key: `u-${user.id}`,
            label: `@${user.name}`,
            color: user.color,
            insert: `@${user.name} `,
        }))
})

watch(suggestions, () => {
    highlighted.value = 0
})

watch(
    () => props.limits?.seconds_left,
    (seconds) => startCooldown(seconds ?? 0),
    { immediate: true },
)

function startCooldown(seconds) {
    cooldown.value = Math.max(0, Math.ceil(seconds || 0))

    if (cooldownTimer) clearInterval(cooldownTimer)

    if (cooldown.value <= 0) return

    cooldownTimer = setInterval(() => {
        cooldown.value -= 1

        if (cooldown.value <= 0) clearInterval(cooldownTimer)
    }, 1000)
}

onUnmounted(() => cooldownTimer && clearInterval(cooldownTimer))

function insert(text, replaceFrom = null) {
    const value = props.modelValue
    const next = replaceFrom === null ? value + text : value.slice(0, replaceFrom) + text

    emit('update:modelValue', next)
    nextTick(() => textarea.value?.focus())
}

function applySuggestion(suggestion) {
    insert(suggestion.insert, trigger.value?.start ?? null)
}

function submit() {
    if (disabled.value || props.sending || !props.modelValue.trim()) return

    emit('send')
}

function onKeydown(event) {
    if (suggestions.value.length > 0) {
        if (event.key === 'ArrowDown') {
            event.preventDefault()
            highlighted.value = (highlighted.value + 1) % suggestions.value.length

            return
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault()
            highlighted.value = (highlighted.value - 1 + suggestions.value.length) % suggestions.value.length

            return
        }

        if (event.key === 'Tab' || (event.key === 'Enter' && !event.shiftKey)) {
            event.preventDefault()
            applySuggestion(suggestions.value[highlighted.value])

            return
        }

        if (event.key === 'Escape') {
            event.preventDefault()
            dismissed.value = true

            return
        }
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault()
        submit()

        return
    }

    if (event.key === 'Escape' && props.replyTo) {
        emit('cancel-reply')
    }
}

function onInput(event) {
    dismissed.value = false
    emit('update:modelValue', event.target.value)
    autoGrow(event)
}

function autoGrow(event) {
    const element = event.target
    element.style.height = 'auto'
    element.style.height = `${Math.min(element.scrollHeight, 120)}px`
}

defineExpose({ focus: () => textarea.value?.focus(), insert })
</script>

<template>
    <div class="relative border-t border-primary-800 bg-primary-950 px-3 py-2.5">
        <!-- Reply context -->
        <div
            v-if="replyTo"
            class="mb-2 flex items-center justify-between gap-2 rounded-md bg-primary-900 px-2 py-1 text-xs text-primary-300"
        >
            <span class="truncate">
                Replying to <span class="font-medium">{{ replyTo.name }}</span
                >: {{ replyTo.body }}
            </span>
            <button type="button" class="text-primary-400 hover:text-primary-100" @click="emit('cancel-reply')">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>

        <!-- Autocomplete -->
        <div
            v-if="suggestions.length"
            class="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-lg border border-primary-700 bg-primary-950 shadow-2xl"
        >
            <button
                v-for="(suggestion, index) in suggestions"
                :key="suggestion.key"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm"
                :class="index === highlighted ? 'bg-primary-800 text-primary-50' : 'text-primary-300 hover:bg-primary-900'"
                @mouseenter="highlighted = index"
                @click="applySuggestion(suggestion)"
            >
                <img v-if="suggestion.image" :src="suggestion.image" class="h-6 w-6 shrink-0" :alt="suggestion.label" />
                <span class="shrink-0 whitespace-nowrap" :style="suggestion.color ? { color: suggestion.color } : undefined">{{ suggestion.label }}</span>
                <span v-if="suggestion.hint" class="min-w-0 flex-1 truncate text-xs text-primary-500">{{ suggestion.hint }}</span>
            </button>
        </div>

        <!-- Emote picker -->
        <div v-if="emotesEnabled && showPicker" class="absolute bottom-full left-3 right-3 mb-2">
            <EmotePicker @close="showPicker = false" @select="(text) => insert(text)" />
        </div>

        <div class="flex items-end gap-2">
            <div class="relative flex-1">
                <textarea
                    ref="textarea"
                    :value="modelValue"
                    :placeholder="placeholder"
                    :disabled="disabled"
                    :maxlength="maxLength"
                    rows="1"
                    class="w-full resize-none rounded-lg border border-primary-800 bg-primary-900 px-3 py-2 pr-10 text-sm text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    @input="
                        emit('update:modelValue', $event.target.value);
                        autoGrow($event);
                    "
                    @keydown="onKeydown"
                />
                <button
                    v-if="emotesEnabled"
                    type="button"
                    class="absolute bottom-1.5 right-1.5 rounded p-1 text-primary-400 hover:bg-primary-800 hover:text-primary-100 disabled:opacity-50"
                    :disabled="disabled"
                    title="Emotes"
                    @click="showPicker = !showPicker"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M9 10h.01M15 10h.01M8.5 14.5a4.5 4.5 0 007 0" />
                    </svg>
                </button>
            </div>

            <button
                type="button"
                class="h-9 shrink-0 rounded-lg bg-primary-600 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="disabled || sending || !modelValue.trim() || cooldown > 0"
                @click="submit"
            >
                {{ cooldown > 0 ? `${cooldown}s` : sending ? '…' : 'Chat' }}
            </button>
        </div>

        <div class="mt-1 flex min-h-[1rem] items-center justify-between text-xs">
            <span v-if="silenced" class="text-red-400">{{ silenced.message }}</span>
            <span v-else-if="error" class="text-red-400">{{ error }}</span>
            <span v-else-if="settings.slow_mode_seconds > 0" class="text-primary-400">
                Slow mode: one message every {{ settings.slow_mode_seconds }}s
            </span>
            <span v-else />

            <span v-if="nearLimit" :class="length >= maxLength ? 'text-red-400' : 'text-primary-500'">
                {{ length }}/{{ maxLength }}
            </span>
        </div>
    </div>
</template>
