<script setup>
import 'vue3-emoji-picker/css'
import EmojiPicker from 'vue3-emoji-picker'
import {onMounted, reactive, ref, watch} from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import FaIconBold from "@/Components/Icons/FaIconBold.vue";
import RateLimitInfoBox from "@/Components/Livestream/RateLimitInfoBox.vue";

const props = defineProps(['modelValue', 'error', 'rateLimit'])
const emit = defineEmits(['update:modelValue', 'sendMessage'])

let cursorPosition = ref(0)
let emojiPicker = ref(null)
let blockSendingDueToRateLimit = ref(props.rateLimit.secondsLeft > 0)
function onSelectEmoji(emoji) {
    // Insert the emoji.i at cursor position
    let newMessage = props.modelValue.slice(0, cursorPosition.value) + emoji.i + props.modelValue.slice(cursorPosition.value)
    emit('update:modelValue', newMessage)
}

function captureCursorPosition(event) {
    cursorPosition.value = event.target.selectionStart
}

let showEmojiPicker = ref(false);
</script>
<template>
    <div class="p-3">
        <RateLimitInfoBox @rate-limit-guard="blockSendingDueToRateLimit = $event" :rate-limit="props.rateLimit"></RateLimitInfoBox>
        <textarea
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            @touchend="captureCursorPosition"
            @keyup="captureCursorPosition"
            @mouseup="captureCursorPosition"
            @keydown.esc="showEmojiPicker = false"
            @keydown.exact.enter.prevent="$emit('sendMessage')"

            rows="24"
            placeholder="Send a chat message"
            class="form-input resize-none max-h-24 overflow-auto w-full rounded-lg bg-primary-200 text-primary-100 border-1 focus:border-transparent focus:ring-1 focus:ring-primary-600 bg-transparent border-primary-500"/>
        <transition>
            <EmojiPicker ref="emojiPicker" :display-recent="true" class="absolute bottom-[160px]"
                         v-show="showEmojiPicker" theme="dark" :native="true"
                         :disable-skin-tones="true"
                         @mouseleave="showEmojiPicker = false"
                         @select="onSelectEmoji"/>
        </transition>
        <div class="flex gap-3 justify-between">
            <transition>
                <div class="text-red-400">{{ error }}</div>
            </transition>
            <div class="flex gap-3 justify-end self-baseline">
                <button
                    @keydown.esc="showEmojiPicker = false"
                    class="py-1 px-2 rounded-lg hover:text-primary-400 transition duration-200 text-primary-300"
                    @click="showEmojiPicker = !showEmojiPicker">
                    <FaIconBold class="fill-current"></FaIconBold>
                </button>
                <button
                    @click="$emit('sendMessage')"
                    :disabled="modelValue.length === 0 || blockSendingDueToRateLimit"
                    class="py-1 px-4 rounded-lg text-primary-300 font-semibold bg-primary-500 enabled:hover:bg-primary-700 transition">
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
