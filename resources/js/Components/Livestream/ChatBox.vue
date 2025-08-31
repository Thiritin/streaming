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
const commandFeedback = ref([])

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

function showCommandFeedback(message, type = 'info', data = {}) {
    const currentTime = new Date();
    const feedbackId = `feedback_${Date.now()}`;
    
    // Add feedback message to chat as a system message
    chatMessages.value.push({
        id: feedbackId,
        name: null, // System message has no name
        time: padWithZeros(currentTime.getHours()) + ':' + padWithZeros(currentTime.getMinutes()),
        message: message,
        is_command: false,
        type: 'system',
        feedback_type: type, // success, error, warning, info
        data: data
    });
    
    scrollToBottom();
    
    // Auto-remove info messages after 10 seconds
    if (type === 'info') {
        setTimeout(() => {
            chatMessages.value = chatMessages.value.filter(msg => msg.id !== feedbackId);
        }, 10000);
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
            // Add the message type to the message object if not present
            if (!e.type) {
                e.type = 'user';
            }
            chatMessages.value.push(e);
            scrollToBottom();
            clearOldMessages();
        })
        .listen('.messagesDeleted', (e) => {
            console.log('Received messagesDeleted event:', e);
            console.log('IDs to delete:', e.ids);
            console.log('Current messages:', chatMessages.value.map(m => ({ id: m.id, name: m.name })));
            
            // Count messages before deletion for feedback
            const beforeCount = chatMessages.value.length;
            
            chatMessages.value = chatMessages.value.filter((message) => {
                // Make sure message id is not in e.ids
                const shouldKeep = !e.ids.includes(message.id);
                if (!shouldKeep) {
                    console.log('Deleting message:', message.id, message.name, message.message);
                }
                return shouldKeep;
            })
            
            const deletedCount = beforeCount - chatMessages.value.length;
            console.log('Deleted count:', deletedCount);
            
            // Show system message about the deletion if messages were removed
            if (deletedCount > 0) {
                const currentTime = new Date();
                chatMessages.value.push({
                    id: `system_${Date.now()}`,
                    name: null,
                    time: padWithZeros(currentTime.getHours()) + ':' + padWithZeros(currentTime.getMinutes()),
                    message: `${deletedCount} message${deletedCount > 1 ? 's' : ''} deleted by a moderator`,
                    is_command: false,
                    type: 'system',
                    feedback_type: 'warning'
                });
                scrollToBottom();
            }
        })
        .listen('.rateLimit', (e) => {
            rateLimit.value.slowMode = e.slowMode;
            rateLimit.value.rateDecay = e.rateDecay;
            rateLimit.value.maxTries = e.maxTries;
        });

    // Register private command feedback listener
    if (page.props.auth?.user) {
        Echo
            .private(`user.${page.props.auth.user.id}`)
            .listen('.command.feedback', (e) => {
                showCommandFeedback(e.message, e.type, e.data);
            });
    }

    // Wait 2 seconds before scrolling to bottom
    setTimeout(() => {
        scrollToBottom();
        clearOldMessages();
    }, 2000);
})
const currentTime = new Date();
let chatMessages = ref(props.chatMessages);

function getRoleBadgeText(role) {
    if (!role || !role.slug) return '';
    
    // Role abbreviations for existing roles
    const abbreviations = {
        'admin': 'ADM',
        'moderator': 'MOD',
        'supersponsor': 'S',
        'sponsor': 'S',
        'staff': 'STF',
        'attendee': 'ATT'
    };
    
    // Return abbreviation if exists, otherwise first 3 letters
    return abbreviations[role.slug.toLowerCase()] || 
           role.name.substring(0, 3).toUpperCase();
}

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

async function sendMessage() {
    if (message.value.length === 0) return;
    
    const cleanInput = message.value.trim();
    
    // Check if it's a command
    if (cleanInput.startsWith('/')) {
        // Handle commands via API
        try {
            const response = await axios.post('/api/command/execute', {
                command: cleanInput
            });
            
            // Clear input on success
            message.value = '';
            error.value = '';
            
            // Show success feedback if provided
            if (response.data?.message) {
                showCommandFeedback(response.data.message, 'success');
            }
        } catch (e) {
            error.value = e.response?.data?.error || 'Command execution failed';
            console.error('Command execution failed:', e);
        }
        return;
    }
    
    // Regular message handling
    axios.post(route('message.send'), {
        message: message.value
    }).then((response) => {
        error.value = '';
        const currentTime = new Date();
        chatMessages.value.push({
            'id': response.data.message_id || null,
            'name': usePage().props.auth.user.name,
            "time": padWithZeros(currentTime.getHours()) + ':' + padWithZeros(currentTime.getMinutes()),
            "message": message.value,
            "is_command": false,
            "role": usePage().props.auth.user.role,
            "chat_color": usePage().props.auth.user.chat_color
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
                <!-- System announcements (broadcast messages) -->
                <div v-if="message.type === 'announcement'" 
                     :class="[
                        'rounded-lg m-2 p-3 break-words border-2',
                        message.priority === 'high' ? 
                            'bg-gradient-to-r from-yellow-900/50 to-orange-900/50 text-yellow-100 border-yellow-600' :
                            'bg-gradient-to-r from-blue-900/50 to-purple-900/50 text-blue-100 border-blue-600'
                     ]">
                    <div class="flex items-start">
                        <span class="text-xs text-primary-300 mr-2 font-mono">{{ message.time }}</span>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-bold bg-yellow-600 text-yellow-100 rounded uppercase tracking-wider">
                                    📢 ANNOUNCEMENT
                                </span>
                            </div>
                            <div class="text-sm font-medium" v-html="processMessageForDisplay(message.message)"></div>
                        </div>
                    </div>
                </div>
                <!-- Regular user messages -->
                <div v-else-if="message.type === 'user' && message.name" class="flex">
                    <div class="text-xs pr-2 text-primary-400 mt-1 font-mono">{{ message.time }}</div>
                    <div :class="{'bg-primary-800/70 text-primary-200 py-1 px-1 rounded': message.is_command}">
                        <span class="inline-flex items-center gap-1.5">
                            <!-- Badge based on role -->
                            <span v-if="message.role" 
                                  class="inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold rounded-sm uppercase tracking-wider"
                                  :style="{
                                      backgroundColor: message.role.chat_color + '30',
                                      borderColor: message.role.chat_color,
                                      color: message.role.chat_color
                                  }"
                                  :title="message.role.name"
                                  style="border-width: 1px; border-style: solid; min-width: 24px; text-shadow: 0 0 2px rgba(0,0,0,0.3);">
                                {{ getRoleBadgeText(message.role) }}
                            </span>
                            <!-- Username -->
                            <span :title="message.role?.name || 'User'" class="font-semibold" :style="{color: message.role?.chat_color || '#86efac'}">
                                {{ message.name }}
                            </span>
                        </span>: <span class="message-content text-primary-100" v-html="processMessageForDisplay(message.message)"></span>
                    </div>
                </div>
                <!-- System messages / Command feedback -->
                <div v-else-if="message.type === 'system'" 
                     :class="[
                        'rounded-lg m-2 p-2 break-words',
                        message.feedback_type === 'success' ? 'bg-green-900/50 text-green-200 border border-green-700' :
                        message.feedback_type === 'error' ? 'bg-red-900/50 text-red-200 border border-red-700' :
                        message.feedback_type === 'warning' ? 'bg-yellow-900/50 text-yellow-200 border border-yellow-700' :
                        'bg-primary-800/70 text-primary-100 border border-primary-700'
                     ]">
                    <div class="flex items-start">
                        <span class="text-xs text-primary-400 mr-2 font-mono">{{ message.time }}</span>
                        <div class="flex-1">
                            <span class="font-semibold text-xs uppercase tracking-wider mr-2">System</span>
                            <span v-html="message.message"></span>
                        </div>
                    </div>
                </div>
                <!-- Legacy messages without type (backward compatibility) -->
                <div v-else-if="!message.type && message.name" class="flex">
                    <div class="text-xs pr-2 text-primary-400 mt-1 font-mono">{{ message.time }}</div>
                    <div :class="{'bg-primary-800/70 text-primary-200 py-1 px-1 rounded': message.is_command}">
                        <span class="inline-flex items-center gap-1.5">
                            <!-- Badge based on role -->
                            <span v-if="message.role" 
                                  class="inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold rounded-sm uppercase tracking-wider"
                                  :style="{
                                      backgroundColor: message.role.chat_color + '30',
                                      borderColor: message.role.chat_color,
                                      color: message.role.chat_color
                                  }"
                                  :title="message.role.name"
                                  style="border-width: 1px; border-style: solid; min-width: 24px; text-shadow: 0 0 2px rgba(0,0,0,0.3);">
                                {{ getRoleBadgeText(message.role) }}
                            </span>
                            <!-- Username -->
                            <span :title="message.role?.name || 'User'" class="font-semibold" :style="{color: message.role?.chat_color || '#86efac'}">
                                {{ message.name }}
                            </span>
                        </span>: <span class="message-content text-primary-100" v-html="processMessageForDisplay(message.message)"></span>
                    </div>
                </div>
                <!-- General system messages (old format) -->
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
