<script setup>
import 'vue3-emoji-picker/css'
import EmojiPicker from 'vue3-emoji-picker'
import {onMounted, reactive, ref, watch, computed} from "vue";
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from "@/Components/PrimaryButton.vue";
import FaIconBold from "@/Components/Icons/FaIconBold.vue";
import RateLimitInfoBox from "@/Components/Livestream/RateLimitInfoBox.vue";
import CommandSuggestion from "@/Components/Livestream/CommandSuggestion.vue";

const props = defineProps(['modelValue', 'error', 'rateLimit'])
const emit = defineEmits(['update:modelValue', 'sendMessage'])

const page = usePage();
let cursorPosition = ref(0)
let emojiPicker = ref(null)
let commandSuggestion = ref(null)
let messageInput = ref(null)
let blockSendingDueToRateLimit = ref(props.rateLimit.secondsLeft > 0)
let showEmojiPicker = ref(false);
let showCommandSuggestions = ref(false);

// Get max message length from config
const maxMessageLength = computed(() => {
    return page.props.chat?.config?.maxMessageLength || 500;
});

// Character count
const characterCount = computed(() => {
    return props.modelValue ? props.modelValue.length : 0;
});

const isOverLimit = computed(() => {
    return characterCount.value > maxMessageLength.value;
});

function onSelectEmoji(emoji) {
    // Insert the emoji.i at cursor position
    let newMessage = props.modelValue.slice(0, cursorPosition.value) + emoji.i + props.modelValue.slice(cursorPosition.value)
    emit('update:modelValue', newMessage)
}

function captureCursorPosition(event) {
    cursorPosition.value = event.target.selectionStart
}

// Watch for command trigger
watch(() => props.modelValue, (newValue) => {
    if (newValue && newValue.startsWith('/')) {
        showCommandSuggestions.value = true;
    } else {
        showCommandSuggestions.value = false;
    }
});

function handleInput(event) {
    emit('update:modelValue', event.target.value);
}

function handleKeyDown(event) {
    // If command suggestions are visible, let the component handle arrow keys
    if (showCommandSuggestions.value && commandSuggestion.value) {
        if (['ArrowUp', 'ArrowDown', 'Tab', 'Enter', 'Escape'].includes(event.key)) {
            commandSuggestion.value.handleKeyDown(event);
            return;
        }
    }
    
    // Handle regular enter for sending
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        if (!isOverLimit.value && characterCount.value > 0) {
            emit('sendMessage');
        }
    }
    
    // Escape closes emoji picker
    if (event.key === 'Escape') {
        showEmojiPicker.value = false;
        showCommandSuggestions.value = false;
    }
}

function onCommandSelect(command) {
    // Focus back on input after selecting command
    messageInput.value?.focus();
}
</script>
<template>
    <div class="p-3 relative">
        <RateLimitInfoBox @rate-limit-guard="blockSendingDueToRateLimit = $event" :rate-limit="props.rateLimit"></RateLimitInfoBox>
        
        <!-- Command Suggestions -->
        <CommandSuggestion
            ref="commandSuggestion"
            v-model="modelValue"
            :visible="showCommandSuggestions"
            @close="showCommandSuggestions = false"
            @select="onCommandSelect"
            @update:modelValue="$emit('update:modelValue', $event)"
        />
        
        <div class="relative">
            <textarea
                ref="messageInput"
                :value="modelValue"
                @input="handleInput"
                @touchend="captureCursorPosition"
                @keyup="captureCursorPosition"
                @mouseup="captureCursorPosition"
                @keydown="handleKeyDown"
                :maxlength="maxMessageLength"
                rows="3"
                placeholder="Send a chat message (type / for commands)"
                :class="[
                    'form-input resize-none max-h-24 overflow-auto w-full rounded-lg bg-primary-200 text-primary-100 border-1 focus:border-transparent focus:ring-1 bg-transparent',
                    isOverLimit ? 'border-red-500 focus:ring-red-500' : 'border-primary-500 focus:ring-primary-600'
                ]"/>
        <transition>
            <EmojiPicker ref="emojiPicker" :display-recent="true" class="absolute bottom-[160px]"
                         v-show="showEmojiPicker" theme="dark" :native="true"
                         :disable-skin-tones="true"
                         @mouseleave="showEmojiPicker = false"
                         @select="onSelectEmoji"/>
        </transition>
            <!-- Character counter -->
            <div class="absolute bottom-2 right-2 text-xs" :class="isOverLimit ? 'text-red-400' : 'text-gray-500'">
                {{ characterCount }}/{{ maxMessageLength }}
            </div>
        </div>
        
        <div class="flex gap-3 justify-between mt-2">
            <div class="flex items-center gap-2">
                <transition>
                    <div v-if="error" class="text-red-400 text-sm">{{ error }}</div>
                </transition>
            </div>
            <div class="flex gap-3 justify-end self-baseline">
                <button
                    @keydown.esc="showEmojiPicker = false"
                    class="py-1 px-2 rounded-lg hover:text-primary-400 transition duration-200 text-primary-300"
                    @click="showEmojiPicker = !showEmojiPicker">
                    <FaIconBold class="fill-current"></FaIconBold>
                </button>
                <button
                    @click="$emit('sendMessage')"
                    :disabled="characterCount === 0 || blockSendingDueToRateLimit || isOverLimit"
                    class="py-1 px-4 rounded-lg text-primary-300 font-semibold bg-primary-500 enabled:hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition">
                    Send
                </button>
            </div>
        </div>
    </div>
</template>
<style scoped>
.v-enter-active,
.v-leave-active {
    transition: opacity 0.1s ease;
}

.v-enter-from,
.v-leave-to {
    opacity: 0;
}

/* ===== Scrollbar CSS ===== */
/* Firefox */
* {
    scrollbar-width: thin;
    scrollbar-color: #003532 #c0e40c;
}

/* Chrome, Edge, and Safari */
*::-webkit-scrollbar {
    width: 10px;
}

*::-webkit-scrollbar-track {
    background: none;
}

*::-webkit-scrollbar-thumb {
    background-color: #003532;
    border-radius: 8px;
    border: none;
}
</style>
