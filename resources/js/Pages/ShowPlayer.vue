<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import StreamPlayer from "@/Components/Livestream/StreamPlayer.vue";
import ChatBox from "@/Components/Livestream/ChatBox.vue";
import StreamOfflineStatusPage from "@/Components/Livestream/StatusPages/StreamOfflineStatusPage.vue";
import StreamProvisioningStatusPage from "@/Components/Livestream/StatusPages/StreamProvisioningStatusPage.vue";
import StreamOtherDeviceStatusPage from "@/Components/Livestream/StatusPages/StreamOtherDeviceStatusPage.vue";
import StreamTechnicalIssuesStatusPage from "@/Components/Livestream/StatusPages/StreamTechnicalIssuesStatusPage.vue";
import StreamStartingSoonStatusPage from "@/Components/Livestream/StatusPages/StreamStartingSoonStatusPage.vue";
import ShowTile from "@/Components/Shows/ShowTile.vue";
import MobileDrawer from "@/Components/MobileDrawer.vue";
import Container from "@/Components/Container.vue";

// Define the layout using defineOptions for persistent layout
defineOptions({
    layout: AuthenticatedLayout
});

// Props
const props = defineProps({
    currentShow: {
        type: Object,
        required: false,
    },
    availableShows: {
        type: Array,
        required: false,
        default: () => []
    },
    initialHlsUrls: {
        type: Object,
        required: false,
    },
    initialStatus: {
        type: String,
        required: true,
    },
    initialListeners: {
        type: Number,
        required: true,
    },
    initialProvisioning: {
        type: Boolean,
        required: false
    },
    initialOtherDevice: {
        type: Boolean,
        required: false
    },
    chatMessages: {
        type: Array,
        required: false
    },
    rateLimit: {
        type: Object,
        required: false
    }
});

// Reactive state
const otherDevice = ref(props.initialOtherDevice);
const activeShow = ref(props.currentShow);
const shows = ref(props.availableShows);
const hlsUrls = ref(props.initialHlsUrls);
const status = ref(props.initialStatus);
const listeners = ref(props.initialListeners);
const provisioning = ref(props.initialProvisioning);
const streamPlayer = ref(null);
const isChatDrawerOpen = ref(false);

// Computed properties
const showChatBox = computed(() => status.value !== 'offline');
const showPlayer = computed(() => activeShow.value && hlsUrls.value && status.value === 'online' && provisioning.value === false && otherDevice.value === false);
const showTitle = computed(() => activeShow.value ? activeShow.value.title : 'No Show Active');
const otherLiveShows = computed(() => shows.value.filter(s => s.id !== activeShow.value?.id && s.status === 'live' && s.slug));

// Methods
const isMobile = () => {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
};

const shouldUseLowerResolution = () => {
    // Check for Network Information API support
    if ('connection' in navigator) {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

        // Check for effectiveType property (slow-2g, 2g, 3g, 4g)
        if ('effectiveType' in connection) {
            const effectiveType = connection.effectiveType;

            // Use lower resolution for slow-2g, 2g, or 3g connections
            if (['slow-2g', '2g', '3g'].includes(effectiveType)) {
                return true;
            }
        }
    }

    // Fallback to mobile check if Network Information API is not supported or did not indicate a slow connection
    return isMobile();
};

// Heartbeat functionality
let heartbeatInterval = null;

const sendHeartbeat = async () => {
    if (!activeShow.value?.source_id) return;
    
    try {
        const response = await axios.post(`/sources/${activeShow.value.source_id}/heartbeat`);
        if (response.data.active_viewers !== undefined) {
            listeners.value = response.data.active_viewers;
        }
    } catch (error) {
        console.error('Heartbeat failed:', error);
    }
};

const startHeartbeat = () => {
    if (heartbeatInterval) return;
    
    // Send initial heartbeat
    sendHeartbeat();
    
    // Send heartbeat every 90 seconds
    heartbeatInterval = setInterval(sendHeartbeat, 90000);
};

const stopHeartbeat = () => {
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
        heartbeatInterval = null;
    }
};

// Lifecycle
onMounted(() => {
    // Start heartbeat if we have a show with a source
    if (activeShow.value?.source_id && status.value === 'online') {
        startHeartbeat();
    }
    
    Echo.channel('StreamInfo')
        .listen('.stream.status.changed', (e) => {
            if (status.value === 'provisioning') {
                return false;
            }
            status.value = e.status;
            
            // Start/stop heartbeat based on status
            if (e.status === 'online' && activeShow.value?.source_id) {
                startHeartbeat();
            } else {
                stopHeartbeat();
            }
        })
        .listen('.stream.listeners.changed', (e) => {
            listeners.value = e.listeners;
        });

    // Listen for show updates
    Echo.channel('shows')
        .listen('.show.status.changed', (e) => {
            // Update show status in the list
            const showIndex = shows.value.findIndex(s => s.id === e.show.id);
            if (showIndex !== -1) {
                // Preserve slug if not provided in the event
                shows.value[showIndex] = {
                    ...shows.value[showIndex], 
                    ...e.show,
                    slug: e.show.slug || shows.value[showIndex].slug
                };
            }

            // If it's the active show, update HLS URLs
            if (activeShow.value && activeShow.value.id === e.show.id) {
                activeShow.value = {
                    ...activeShow.value,
                    ...e.show,
                    slug: e.show.slug || activeShow.value.slug
                };
                if (e.hlsUrls) {
                    hlsUrls.value = e.hlsUrls;
                }
            }
        })
        .listen('.show.source.changed', (e) => {
            // Handle source switching for a show
            if (activeShow.value && activeShow.value.id === e.show.id) {
                hlsUrls.value = e.hlsUrls;
                // Restart heartbeat with new source
                if (e.show.source_id && status.value === 'online') {
                    activeShow.value.source_id = e.show.source_id;
                    stopHeartbeat();
                    startHeartbeat();
                }
            }
        });
});

// Cleanup on unmount
onUnmounted(() => {
    stopHeartbeat();
});
</script>

<template>
    <div>
        <Head>
            <title>{{ showTitle }} - Stream</title>
        </Head>

        <!-- Back to Shows Button -->
        <div class="bg-primary-900 border-b border-primary-800 px-4 py-2">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <Link :href="route('shows.grid')" class="inline-flex items-center text-primary-400 hover:text-white transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        <span class="hidden sm:inline">Back to Shows</span>
                        <span class="sm:hidden">Back</span>
                    </Link>
                    <span class="mx-3 text-primary-600 hidden sm:inline">|</span>
                    <span class="text-white font-semibold ml-3 sm:ml-0 truncate">{{ showTitle }}</span>
                    <span v-if="activeShow?.source" class="text-primary-400 ml-2 hidden sm:inline">• {{ activeShow.source }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Mobile Chat Button -->
                    <button 
                        v-if="showChatBox"
                        @click="isChatDrawerOpen = true"
                        class="md:hidden inline-flex items-center px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors relative"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <span class="ml-1">Chat</span>
                        <!-- Unread indicator -->
                        <span v-if="chatMessages?.length > 0" class="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                    </button>
                    
                    <!-- External Player Link -->
                    <Link
                        v-if="activeShow && activeShow.slug"
                        :href="route('show.external', activeShow.slug)"
                        class="inline-flex items-center px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors"
                    >
                        <svg class="w-4 h-4 sm:mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        <span class="hidden sm:inline ml-1">External Player</span>
                    </Link>
                </div>
            </div>
        </div>

        <div class="flex flex-col md:flex-row md:max-h-[calc(100vh-3rem)] grow md:overflow-hidden">
            <!-- Livestream -->
            <div class="w-full flex-1 overflow-auto">
                <div v-if="showPlayer">
                    <StreamPlayer ref="streamPlayer"
                                  :hls-urls="hlsUrls"
                                  :show-info="activeShow"
                                  class="z-10 relative w-full bg-black max-h-[calc(100vh_-_10vh)]"></StreamPlayer>

                    <!-- Player Controls Bar -->
                    <div class="player-controls-bar bg-primary-900 border-t border-primary-800 px-4 py-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <!-- Stats Toggle -->
                                <button @click="streamPlayer?.handleToggleStats()" 
                                        class="px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors flex items-center gap-2"
                                        title="Stream Statistics (I)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                    </svg>
                                    Stats
                                </button>
                                
                                <!-- Keyboard Shortcuts Help -->
                                <button @click="streamPlayer?.handleShowKeyboardHelp()" 
                                        class="px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors flex items-center gap-2"
                                        title="Keyboard Shortcuts (?)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Help
                                </button>
                            </div>
                            <div class="flex items-center gap-2 text-sm text-primary-400">
                                <span>{{ listeners }} viewers</span>
                            </div>
                        </div>
                    </div>

                    <!-- Stream Information -->
                    <Container class="bg-primary-800 border-t-2 border-primary-700" padding="p-6">
                        <h2 class="text-2xl font-bold text-white mb-3">{{ activeShow?.title || 'Stream' }}</h2>
                        <p v-if="activeShow?.description" class="text-primary-200 text-lg leading-relaxed mb-4">{{ activeShow.description }}</p>
                        <div v-if="activeShow?.source" class="flex items-center gap-2 text-sm">
                            <span class="font-semibold text-primary-300">Source:</span>
                            <span class="text-primary-400">{{ activeShow.source }}</span>
                        </div>
                    </Container>

                    <!-- Other Live Shows -->
                    <Container v-if="otherLiveShows.length > 0" class="bg-black/50 border-t border-primary-800" padding="p-6">
                        <div class="flex items-center mb-6">
                            <h2 class="text-xl font-semibold text-white">Other Live Shows</h2>
                            <span class="ml-3 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold uppercase animate-pulse">
                                {{ otherLiveShows.length }} LIVE
                            </span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                            <div v-for="show in otherLiveShows" :key="show.id" class="transform transition-transform hover:scale-105">
                                <ShowTile :show="show" />
                            </div>
                        </div>
                    </Container>
                </div>
                <div v-else-if="status === 'starting_soon'">
                    <StreamStartingSoonStatusPage></StreamStartingSoonStatusPage>
                </div>
                <div v-else-if="provisioning === true && status !== 'offline'">
                    <StreamProvisioningStatusPage></StreamProvisioningStatusPage>
                </div>
                <div v-else-if="otherDevice === true && status !== 'offline'">
                    <StreamOtherDeviceStatusPage
                        @endStreamOnOtherDevice="otherDevice = false"></StreamOtherDeviceStatusPage>
                </div>
                <div v-else-if="status === 'technical_issue'">
                    <StreamTechnicalIssuesStatusPage :listeners="listeners"></StreamTechnicalIssuesStatusPage>
                </div>
                <div v-else>
                    <StreamOfflineStatusPage></StreamOfflineStatusPage>
                </div>
                
                <!-- Stream Information for non-player states -->
                <Container v-if="!showPlayer && activeShow" class="bg-primary-800 border-t-2 border-primary-700" padding="p-6">
                    <h2 class="text-2xl font-bold text-white mb-3">{{ activeShow.title }}</h2>
                    <p v-if="activeShow.description" class="text-primary-200 text-lg leading-relaxed mb-4">{{ activeShow.description }}</p>
                    <div v-if="activeShow.source" class="flex items-center gap-2 text-sm">
                        <span class="font-semibold text-primary-300">Source:</span>
                        <span class="text-primary-400">{{ activeShow.source }}</span>
                    </div>
                </Container>
                
                <!-- Other Live Shows for non-player states -->
                <Container v-if="!showPlayer && otherLiveShows.length > 0" class="bg-black/50 border-t border-primary-800" padding="p-6">
                    <div class="flex items-center mb-6">
                        <h2 class="text-xl font-semibold text-white">Other Live Shows</h2>
                        <span class="ml-3 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold uppercase animate-pulse">
                            {{ otherLiveShows.length }} LIVE
                        </span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        <div v-for="show in otherLiveShows" :key="show.id" class="transform transition-transform hover:scale-105">
                            <ShowTile :show="show" />
                        </div>
                    </div>
                </Container>
            </div>
            <!-- Chat - Desktop Only -->
            <div v-if="showChatBox" class="hidden md:block w-full md:w-1/6 md:min-w-[300px]">
                <ChatBox :rate-limit="rateLimit" :chat-messages="chatMessages"
                         class="h-[600px] md:h-[calc(100vh_-_3rem)] md:overflow-hidden grow"></ChatBox>
            </div>
        </div>
        
        <!-- Floating Chat Button for Mobile -->
        <button 
            v-if="showChatBox && showPlayer && !isChatDrawerOpen"
            @click="isChatDrawerOpen = true"
            class="md:hidden fixed bottom-4 right-4 z-30 bg-primary-700 hover:bg-primary-600 text-white rounded-full p-4 shadow-lg transition-all duration-300 hover:scale-110"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
            </svg>
            <!-- Unread indicator -->
            <span v-if="chatMessages?.length > 0" class="absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full animate-pulse"></span>
        </button>
        
        <!-- Mobile Chat Drawer -->
        <MobileDrawer 
            :is-open="isChatDrawerOpen" 
            @close="isChatDrawerOpen = false"
            position="right"
            width="w-full max-w-sm"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-white">Live Chat</h2>
            </template>
            <ChatBox 
                v-if="showChatBox"
                :rate-limit="rateLimit" 
                :chat-messages="chatMessages"
                class="h-full"
            />
        </MobileDrawer>
    </div>
</template>

<style>
.slide-fade-enter-active {
    transition: all 0.3s ease-out;
}

.slide-fade-leave-active {
    transition: all 0.8s cubic-bezier(1, 0.5, 0.8, 1);
}

.slide-fade-enter-from,
.slide-fade-leave-to {
    transform: translateX(20px);
    opacity: 0;
}


/* ===== Scrollbar CSS ===== */
/* Firefox */
* {
    scrollbar-width: none;
    scrollbar-color: #003532 #c0e40c;
}

/* Chrome, Edge, and Safari */
*::-webkit-scrollbar {
    width: 0px;
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
