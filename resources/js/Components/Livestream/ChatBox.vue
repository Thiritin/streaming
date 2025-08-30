<script setup>
import {onMounted, reactive, ref} from "vue";
import ChatMessageBox from "@/Components/Livestream/ChatMessageBox.vue";
import UserBadge from "@/Components/Livestream/UserBadge.vue";
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
            scrollToBottom();
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

    // Wait 2 seconds before scrolling to bottom
    setTimeout(() => {
        scrollToBottom();
        clearOldMessages();
    }, 2000);
})
const currentTime = new Date();
let chatMessages = ref(props.chatMessages);

function highlightUsername(message) {
  const currentUser = usePage().props.auth.user.name;
  const regex = new RegExp(`@${currentUser}\\b`, 'g');
  return message.replace(regex, `<span class="text-center mx-1 px-1 bg-black">@${currentUser}</span>`);
}

function processMessageForDisplay(message) {
  // Check if message contains emote tags (already processed by server)
  if (message.includes('<emote')) {
    // Parse emote tags and replace with images
    let processed = message.replace(/<emote data-name="([^"]+)" data-url="([^"]+)"(?: data-size="([^"]+)")?><\/emote>/g, 
      (match, name, url, size) => {
        // Use smaller size (16x16) if size is 'small', otherwise 32x32
        const sizeClass = size === 'small' ? 'w-4 h-4' : 'w-8 h-8';
        return `<img src="${url}" alt=":${name}:" title=":${name}:" class="inline-block ${sizeClass} mx-1 align-middle" />`;
      });
    
    // Apply username highlighting
    processed = highlightUsername(processed);
    return processed;
  }
  
  // For messages without emotes, process normally
  let processed = highlightUsername(encodeHtml(message));

  // Apply client-side URL filtering for display (server already sanitized)
  const allowedDomains = page.props.chat?.config?.allowedDomains || ['eurofurence.org'];
  const urlPattern = /(?:https?:\/\/|www\.)(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/[^\s]*)?/gi;

  processed = processed.replace(urlPattern, (match) => {
    // Check if URL is from allowed domain
    for (const domain of allowedDomains) {
      if (match.includes(domain)) {
        return match;
      }
    }
    return '[url removed]';
  });

  return processed;
}

function isCommand(str) {
    const trimmedStr = str.trim();
    return trimmedStr.startsWith('/') || trimmedStr.startsWith('!');
}

function encodeHtml(html) {
    return html.replace(/[\u00A0-\u9999<>\&]/g, i => '&#'+i.charCodeAt(0)+';')
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
            "role": usePage().props.auth.user.role,
            "badge": usePage().props.auth.user.badge
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
    <div class="flex flex-col bg-primary-950">
        <!-- Title -->
        <div class="bg-primary-950 py-4 text-white text-center border-b border-primary-800">
            <h1 class="uppercase tracking-wider font-semibold">Stream Chat</h1>
        </div>
        <!-- Chat Messages -->
        <div class="px-3 p-3 text-white flex-1 h-full overflow-auto bg-primary-900/95" ref="messageContainer">
            <div class="mb-0.5" v-for="message in chatMessages">
                <div class="flex" v-if="message.name">
                    <div class="text-xs pr-2 text-primary-400 mt-1">{{ message.time }}</div>
                    <div :class="{'bg-primary-800/70 text-primary-200 py-1 px-1 rounded': message.is_command}">
                        <UserBadge v-if="message.badge" :badge="message.badge" size="sm" class="mr-1" />
                        <span :title="message.role?.name || 'User'" class="font-semibold" :style="{color: message.role?.chat_color || '#86efac'}">
                            {{ message.name }}<span v-if="message.role?.is_staff"> ({{ message.role.name }})</span>
                        </span>: <span class="message-content text-primary-100" v-html="processMessageForDisplay(message.message)"></span>
                    </div>
                </div>
                <div v-else class="rounded-lg text-center m-2 p-2 break-words bg-primary-800/70 text-primary-100">
                    {{ message.message }}
                </div>
            </div>
        </div>
        <!-- Message Box -->
        <ChatMessageBox :rate-limit="rateLimit" @sendMessage="sendMessage" :error="error" v-model="message"/>
    </div>
</template>

<style>
/* Message content word breaking */
.message-content {
    word-break: break-word;
    overflow-wrap: anywhere;
    hyphens: auto;
}

/* Force break very long strings without spaces */
.message-content :deep(*) {
    word-break: break-all;
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
