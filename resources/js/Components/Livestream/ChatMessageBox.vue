<script setup>
import 'vue3-emoji-picker/css'
import EmojiPicker from 'vue3-emoji-picker'
import {onMounted, onUnmounted, reactive, ref, watch, computed} from "vue";
import { usePage } from '@inertiajs/vue3';
import PrimaryButton from "@/Components/PrimaryButton.vue";
import FaEmojiIcon from "@/Components/Icons/FaEmojiIcon.vue";
import RateLimitInfoBox from "@/Components/Livestream/RateLimitInfoBox.vue";
import CommandSuggestion from "@/Components/Livestream/CommandSuggestion.vue";
import Textarea from "@/Components/ui/Textarea.vue";
import Button from "@/Components/ui/Button.vue";

const props = defineProps(['modelValue', 'error', 'rateLimit'])
const emit = defineEmits(['update:modelValue', 'sendMessage'])

const page = usePage();
let cursorPosition = ref(0)
let emojiPicker = ref(null)
let commandSuggestion = ref(null)
let blockSendingDueToRateLimit = ref(props.rateLimit.secondsLeft > 0)
let showEmojiPicker = ref(false);
let showCommandSuggestions = ref(false);
let showEmoteSuggestions = ref(false);
let emoteSearchTerm = ref('');

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
    showEmojiPicker.value = false; // Close picker after selection
    // Focus on the actual textarea element
    setTimeout(() => {
        const textarea = document.querySelector('textarea');
        if (textarea) textarea.focus();
    }, 50);
}

function toggleEmojiPicker() {
    showEmojiPicker.value = !showEmojiPicker.value;
    if (!showEmojiPicker.value) {
        setTimeout(() => {
            const textarea = document.querySelector('textarea');
            if (textarea) textarea.focus();
        }, 50);
    }
}

function captureCursorPosition(event) {
    if (event && event.target) {
        cursorPosition.value = event.target.selectionStart
    }
}

// Watch for command trigger
watch(() => props.modelValue, (newValue) => {
    // Check for command trigger (at start of message)
    if (newValue && newValue.trim().startsWith('/')) {
        showCommandSuggestions.value = true;
        showEmoteSuggestions.value = false;
        console.log('Command trigger detected:', newValue);
    } else {
        showCommandSuggestions.value = false;
    }
    
    // Check for emote trigger (anywhere in message)
    const emoteMatch = newValue && newValue.match(/:([a-z0-9_]*)$/i);
    if (emoteMatch && !newValue.trim().startsWith('/')) {
        emoteSearchTerm.value = emoteMatch[1];
        showEmoteSuggestions.value = true;
    } else if (!newValue || !newValue.match(/:[a-z0-9_]*$/i)) {
        showEmoteSuggestions.value = false;
    }
});

// Get filtered emotes based on search
const filteredEmotes = computed(() => {
    if (!showEmoteSuggestions.value) return [];
    
    const emotes = page.props.chat?.emotes?.available || {};
    const globalEmotes = page.props.chat?.emotes?.global || [];
    
    // Combine all emotes
    const allEmotes = [
        ...Object.values(emotes),
        ...globalEmotes.filter(e => !emotes[e.name])
    ];
    
    if (!emoteSearchTerm.value) {
        return allEmotes.slice(0, 10); // Show first 10 emotes
    }
    
    return allEmotes
        .filter(emote => emote.name.toLowerCase().includes(emoteSearchTerm.value.toLowerCase()))
        .slice(0, 10);
});

function handleInput(value) {
    emit('update:modelValue', value);
}

function handleKeyDown(event) {
    // If command suggestions are visible, let the component handle arrow keys and tab
    if (showCommandSuggestions.value && commandSuggestion.value) {
        if (['ArrowUp', 'ArrowDown', 'Tab'].includes(event.key)) {
            commandSuggestion.value.handleKeyDown(event);
            return;
        }
        // For Enter key with command suggestions visible
        if (event.key === 'Enter') {
            // Check if there are actually filtered commands to select
            if (commandSuggestion.value.hasFilteredCommands && commandSuggestion.value.hasFilteredCommands()) {
                // Let the command suggestion handle it
                commandSuggestion.value.handleKeyDown(event);
                // Only return if a command was actually selected
                if (event.defaultPrevented) {
                    return;
                }
            }
            // Close suggestions if no commands available
            showCommandSuggestions.value = false;
        }
        // For Escape, always let command suggestions handle it first
        if (event.key === 'Escape') {
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
    }
}

function onCommandSelect(command) {
    // Focus back on input after selecting command
    setTimeout(() => {
        const textarea = document.querySelector('textarea');
        if (textarea) textarea.focus();
    }, 50);
}

function selectEmote(emote) {
    // Replace the partial emote with the full emote
    const currentValue = props.modelValue;
    const lastColonIndex = currentValue.lastIndexOf(':');
    const newValue = currentValue.substring(0, lastColonIndex) + ':' + emote.name + ': ';
    emit('update:modelValue', newValue);
    showEmoteSuggestions.value = false;
    setTimeout(() => {
        const textarea = document.querySelector('textarea');
        if (textarea) textarea.focus();
    }, 50);
}

// Click away handler for emoji picker
function handleClickAway(event) {
    const emojiPicker = document.querySelector('.v3-emoji-picker');
    const emojiButton = event.target.closest('button');
    
    if (emojiPicker && !emojiPicker.contains(event.target) && 
        (!emojiButton || !emojiButton.textContent.includes('😀'))) {
        showEmojiPicker.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickAway);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickAway);
});
</script>
<template>
    <div class="p-3 relative bg-primary-950 border-t border-primary-800">
        <RateLimitInfoBox @rate-limit-guard="blockSendingDueToRateLimit = $event" :rate-limit="props.rateLimit"></RateLimitInfoBox>

        <!-- Command Suggestions -->
        <CommandSuggestion
            ref="commandSuggestion"
            :modelValue="modelValue"
            :visible="showCommandSuggestions"
            @close="showCommandSuggestions = false"
            @select="onCommandSelect"
            @update:modelValue="$emit('update:modelValue', $event)"
            class="z-50"
        />

        <!-- Emote Suggestions -->
        <div v-if="showEmoteSuggestions && filteredEmotes.length > 0"
             class="absolute bottom-full mb-2 left-0 right-0 bg-primary-800 rounded-lg shadow-xl border border-primary-700 max-h-48 overflow-y-auto z-50">
            <div class="p-2">
                <div class="text-xs text-primary-400 uppercase tracking-wider mb-2 px-2">
                    Emotes
                </div>
                <div class="grid grid-cols-5 gap-2">
                    <div v-for="emote in filteredEmotes"
                         :key="emote.id"
                         @click="selectEmote(emote)"
                         class="cursor-pointer hover:bg-primary-700 rounded p-2 text-center transition-colors">
                        <img :src="emote.url" 
                             :alt="':' + emote.name + ':'"
                             :title="':' + emote.name + ':'"
                             class="w-8 h-8 mx-auto mb-1" />
                        <div class="text-xs text-primary-300 truncate">
                            :{{ emote.name }}:
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="relative">
            <Textarea
                :modelValue="modelValue"
                @update:modelValue="handleInput"
                @touchend="captureCursorPosition"
                @keyup="captureCursorPosition"
                @mouseup="captureCursorPosition"
                @keydown="handleKeyDown"
                :maxlength="maxMessageLength"
                :rows="3"
                placeholder="Send a chat message (type / for commands)"
                :class="[
                    'resize-none max-h-24 overflow-auto',
                    isOverLimit ? 'border-red-500 focus:ring-red-500' : ''
                ]"/>
        <EmojiPicker ref="emojiPicker" :display-recent="true" class="absolute bottom-full mb-2 z-50"
                     v-show="showEmojiPicker" theme="dark" :native="true"
                     :disable-skin-tones="true"
                     @select="onSelectEmoji"/>
            <!-- Character counter -->
            <div class="absolute bottom-2 right-2 text-xs" :class="isOverLimit ? 'text-red-400' : 'text-primary-500'">
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
                    @click.stop="toggleEmojiPicker"
                    class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-primary-950 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 text-primary-100 hover:bg-primary-800 h-9 px-3">
                    <FaEmojiIcon class="fill-current"></FaEmojiIcon>
                </button>
                <Button
                    @click="$emit('sendMessage')"
                    :disabled="characterCount === 0 || blockSendingDueToRateLimit || isOverLimit"
                    size="sm">
                    Send
                </Button>
            </div>
        </div>
    </div>
</template>
<style>
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
