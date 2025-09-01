<script setup>
import {onMounted, onUnmounted, reactive, ref, watch, nextTick} from "vue";
import ChatMessageBox from "@/Components/Livestream/ChatMessageBox.vue";
import {usePage, router} from '@inertiajs/vue3'
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
let isSending = ref(false);
let messageContainer = ref(null)
let viewCatcher = ref(null);
let isAutoScrollEnabled = ref(true);
let lastScrollTop = ref(0);
let isLoadingOlder = ref(false);
let hasMoreMessages = ref(true);
let firstMessageId = ref(null);
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

function scrollToBottom(force = false) {
    if (!isAutoScrollEnabled.value && !force) return;
    
    const container = messageContainer.value;
    if (container) {
        setTimeout(() => {
            container.scrollTop = container.scrollHeight;
        }, 50);
    }
}

async function loadOlderMessages() {
    if (isLoadingOlder.value || !hasMoreMessages.value) return;
    
    isLoadingOlder.value = true;
    
    try {
        // Load older messages via API
        const response = await axios.get('/messages/older', {
            params: {
                before_id: firstMessageId.value
            }
        });
        
        if (response.data.messages && response.data.messages.length > 0) {
            // Store current scroll height
            const container = messageContainer.value;
            const previousScrollHeight = container ? container.scrollHeight : 0;
            
            // Prepend older messages to the beginning
            chatMessages.value = [...response.data.messages, ...chatMessages.value];
            
            // Update first message ID for next load
            firstMessageId.value = response.data.messages[0].id;
            
            // Update hasMore flag
            hasMoreMessages.value = response.data.hasMore;
            
            // Preserve scroll position after DOM update
            await nextTick();
            if (container) {
                const newScrollHeight = container.scrollHeight;
                const scrollDiff = newScrollHeight - previousScrollHeight;
                container.scrollTop = container.scrollTop + scrollDiff;
            }
        } else {
            hasMoreMessages.value = false;
        }
    } catch (error) {
        console.error('Failed to load older messages:', error);
    } finally {
        isLoadingOlder.value = false;
    }
}

function handleScroll() {
    const container = messageContainer.value;
    if (!container) return;
    
    const scrollTop = container.scrollTop;
    const scrollHeight = container.scrollHeight;
    const clientHeight = container.clientHeight;
    const isAtBottom = scrollHeight - scrollTop - clientHeight < 50; // 50px threshold
    const isNearTop = scrollTop < 200; // Load when within 200px of top
    
    // Trigger lazy loading when scrolled near the top
    if (isNearTop && !isLoadingOlder.value && hasMoreMessages.value) {
        loadOlderMessages();
    }
    
    // If user scrolled up (away from bottom), disable auto-scroll
    if (!isAtBottom && scrollTop < lastScrollTop.value) {
        isAutoScrollEnabled.value = false;
    }
    // If user scrolled to bottom (or very close), enable auto-scroll
    else if (isAtBottom) {
        isAutoScrollEnabled.value = true;
    }
    
    lastScrollTop.value = scrollTop;
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
    // Initialize first message ID
    if (chatMessages.value.length > 0) {
        firstMessageId.value = chatMessages.value[0].id;
    }
    
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

    // Initial scroll to bottom after a short delay
    setTimeout(() => {
        scrollToBottom(true); // Force scroll on initial load
        clearOldMessages();
    }, 100);
    
    // Add scroll event listener to the message container
    if (messageContainer.value) {
        messageContainer.value.addEventListener('scroll', handleScroll);
    }
})

onUnmounted(() => {
    // Clean up scroll event listener
    if (messageContainer.value) {
        messageContainer.value.removeEventListener('scroll', handleScroll);
    }
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

function processMessageForDisplay(message) {
  // Server already handles HTML escaping and sanitization
  // We just need to convert emote tags to images and highlight usernames
  
  // Parse emote tags and replace with images
  let processed = message.replace(/<emote data-name="([^"]+)" data-url="([^"]+)"(?: data-size="([^"]+)")?><\/emote>/g, 
    (match, name, url, size) => {
      // Use smaller size (16x16) if size is 'small', otherwise 32x32
      const sizeClass = size === 'small' ? 'w-4 h-4' : 'w-8 h-8';
      return `<img src="${url}" alt=":${name}:" title=":${name}:" class="inline-block ${sizeClass} mx-1 align-middle" />`;
    });
  
  // Apply username highlighting (without encoding since server already did it)
  const currentUser = usePage().props.auth.user.name;
  // Escape special regex characters in username
  const escapedUser = currentUser.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`@${escapedUser}\\b`, 'g');
  processed = processed.replace(regex, `<span class="text-center mx-1 px-1 bg-black">@${currentUser}</span>`);
  
  return processed;
}


function isCommand(str) {
    const trimmedStr = str.trim();
    return trimmedStr.startsWith('/') || trimmedStr.startsWith('!');
}

async function sendMessage() {
    if (message.value.length === 0) return;
    if (isSending.value) return; // Prevent double-sending
    
    const cleanInput = message.value.trim();
    
    // Check if it's a command
    if (cleanInput.startsWith('/')) {
        isSending.value = true;
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
        } finally {
            isSending.value = false;
        }
        return;
    }
    
    // Regular message handling
    isSending.value = true;
    const messageToSend = message.value;
    message.value = ''; // Clear input immediately for better UX
    
    axios.post(route('message.send'), {
        message: messageToSend
    }).then((response) => {
        error.value = '';
        // Use the server-processed message with proper ID and emote processing
        if (response.data.message) {
            chatMessages.value.push(response.data.message);
            scrollToBottom();
            clearOldMessages();
        }
        rateLimit.value = response.data.rateLimit;
    }).catch((e) => {
        // Restore message on error
        message.value = messageToSend;
        error.value = e.response?.data?.message
        if (e.response.status === 429) {
            rateLimit.value = e.response.data.rateLimit;
        }
        console.log(e)
    }).finally(() => {
        isSending.value = false;
    });
}

</script>

<template>
    <div class="flex flex-col bg-primary-950 relative h-full">
        <!-- Title -->
        <div class="bg-primary-950 py-4 text-white text-center border-b border-primary-800 flex-shrink-0">
            <h1 class="uppercase tracking-wider font-semibold">Stream Chat</h1>
        </div>
        <!-- Chat Messages -->
        <div class="relative flex-1 min-h-0">
            <!-- Scroll to bottom button -->
            <transition name="fade">
                <button v-if="!isAutoScrollEnabled"
                    @click="scrollToBottom(true); isAutoScrollEnabled = true"
                    class="absolute bottom-2 left-1/2 transform -translate-x-1/2 z-10 bg-primary-700 hover:bg-primary-600 text-white px-3 py-1.5 rounded-full shadow-lg text-sm flex items-center gap-2 transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                    </svg>
                    New messages
                </button>
            </transition>
            <div class="px-3 p-3 text-white absolute inset-0 overflow-auto bg-primary-900/95" ref="messageContainer" @scroll="handleScroll">
            <!-- Loading indicator at the top -->
            <div v-if="isLoadingOlder" class="text-center py-3 text-primary-400">
                <svg class="animate-spin h-5 w-5 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <div class="text-xs mt-1">Loading older messages...</div>
            </div>
            
            <!-- No more messages indicator -->
            <div v-else-if="!hasMoreMessages && chatMessages.length > 0" class="text-center py-2 text-primary-500 text-xs">
                Beginning of chat history
            </div>
            
            <div class="mb-0.5" v-for="message in chatMessages">
                <!-- System announcements (broadcast messages) -->
                <div v-if="message.type === 'announcement'" class="flex">
                    <div class="text-xs pr-2 text-primary-400 mt-1 font-mono">{{ message.time }}</div>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="inline-flex items-center px-2 py-1 text-xs font-bold bg-yellow-600 text-yellow-100 rounded uppercase tracking-wider">
                                ANNOUNCEMENT
                            </span>
                        </div>
                        <div class="text-sm text-primary-100" v-html="processMessageForDisplay(message.message)"></div>
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
        </div>
        <!-- Message Box -->
        <ChatMessageBox :rate-limit="rateLimit" @sendMessage="sendMessage" :error="error" :is-sending="isSending" v-model="message"/>
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

/* Fade transition for scroll to bottom button */
.fade-enter-active, .fade-leave-active {
    transition: opacity 0.3s ease;
}

.fade-enter-from, .fade-leave-to {
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

/* Fade transition for scroll button */
.fade-enter-active, .fade-leave-active {
    transition: opacity 0.3s ease, transform 0.3s ease;
}

.fade-enter-from, .fade-leave-to {
    opacity: 0;
    transform: translate(-50%, 10px);
}
</style>
