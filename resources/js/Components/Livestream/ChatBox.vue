<script setup>
import {onMounted, reactive, ref} from "vue";
import ChatMessageBox from "@/Components/Livestream/ChatMessageBox.vue";
import {usePage} from '@inertiajs/vue3'
import {inject} from 'vue'

const axios = inject('axios')
const preserveClasses = [
    'text-orange-500',
    'text-red-500',
    'text-blue-500',
    'text-yellow-500',
    'text-green-500',
    'text-purple-500',
    'text-pink-500',
];

const page = usePage()

let message = ref('');
let error = ref('');
let messageContainer = ref(null)
let viewCatcher = ref(null);
let props = defineProps({
    chatMessages: {
        type: Array,
        default: () => []
    },
    rateLimit: {
        type: Object
    }
})

const rateLimit = ref(props.rateLimit)

// messageContainer.value?.scrollIntoView({behavior: "smooth"})

function padWithZeros(number) {
    return number.toString().padStart(2, '0');
}

function scrollToBottom() {
    const container = messageContainer.value;
    if (container && container.lastElementChild) {
        setTimeout(() => {
            container.lastElementChild.scrollIntoView({behavior: "smooth", block: "end"});
        }, 50);
    }
}

/**
 * If messages get too big, we need to clear them max 500 messages.
 */
function clearOldMessages() {
    if (chatMessages.value.length > 500) {
        chatMessages.value = chatMessages.value.slice(chatMessages.value.length - 500, chatMessages.value.length);
    }
}

onMounted(() => {
    // Register Chat Listener
    Echo
        .channel('chat')
        .listen('.message', (e) => {
            const currentTime = new Date();
            chatMessages.value.push(e);
        })
        .listen('.messagesDeleted', (e) => {
            chatMessages.value = chatMessages.value.filter((message) => {
                // Make sure message id is not in e.ids
                return !e.ids.includes(message.id);
            })
        })
        .listen('.rateLimit', (e) => {
            rateLimit.value.slowMode = e.slowMode;
            rateLimit.value.rateDecay = e.rateDecay;
            rateLimit.value.maxTries = e.maxTries;
        });
    scrollToBottom();
    clearOldMessages();
})
const currentTime = new Date();
let chatMessages = ref(props.chatMessages);

function isCommand(str) {
    const trimmedStr = str.trim();
    return trimmedStr.startsWith('/') || trimmedStr.startsWith('!');
}

function sendMessage() {
    if (message.value.length === 0) return;
    // Append to chatMessages
    axios.post(route('message.send'), {
        message: message.value
    }).then((response) => {
        error.value = '';
        const currentTime = new Date();
        chatMessages.value.push({
            'id': null,
            'name': usePage().props.auth.user.name,
            "time": padWithZeros(currentTime.getHours()) + ':' + padWithZeros(currentTime.getMinutes()),
            "message": message.value,
            "is_command": isCommand(message.value),
            "role": usePage().props.auth.user.role
        })
        message.value = '';
        scrollToBottom();
        clearOldMessages();
        rateLimit.value = response.data.rateLimit;
    }).catch((e) => {
        error.value = e.response?.data?.message
        if (e.response.status === 429) {
            rateLimit.value = e.response.data.rateLimit;
        }
        console.log(e)
    });
}

</script>

<template>
    <div class="flex flex-col">
        <!-- Title -->
        <div class="bg-primary-700 py-4 text-primary-200 text-center">
            <h1 class="uppercase tracking-wider font-semibold">Stream Chat</h1>
        </div>
        <!-- Chat Messages -->
        <div class="px-3 p-3 text-primary-200 flex-1 h-full overflow-auto" ref="messageContainer">
            <div class="mb-0.5" v-for="message in chatMessages">
                <div class="flex" v-if="message.role !== null">
                    <div class="text-xs pr-2 text-primary-400 mt-1">{{ message.time }}</div>
                    <div :class="{'bg-black text-gray-400 py-1 px-1': message.is_command}">
                            <span class="font-semibold" :class="message.role.color">
                                {{ message.name }}
                            </span>: <span class="text-wrap break-all">{{ message.message }}</span>
                    </div>
                </div>
                <div v-else class="rounded-lg text-center m-2 p-2 bg-primary-600">
                    {{ message.message }}
                </div>
            </div>
        </div>
        <!-- Message Box -->
        <ChatMessageBox :rate-limit="rateLimit" @sendMessage="sendMessage" :error="error" v-model="message"/>
    </div>
</template>

<style scoped>
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
