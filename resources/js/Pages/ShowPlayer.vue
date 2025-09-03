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
import StreamErrorStatusPage from "@/Components/Livestream/StatusPages/StreamErrorStatusPage.vue";
import StreamReconnectingStatusPage from "@/Components/Livestream/StatusPages/StreamReconnectingStatusPage.vue";
import ShowScheduledStatusPage from "@/Components/Livestream/StatusPages/ShowScheduledStatusPage.vue";
import ShowEndedStatusPage from "@/Components/Livestream/StatusPages/ShowEndedStatusPage.vue";
import ShowCancelledStatusPage from "@/Components/Livestream/StatusPages/ShowCancelledStatusPage.vue";
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
    initialHlsUrl: {
        type: String,
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
const hlsUrl = ref(props.initialHlsUrl);
const status = ref(props.initialStatus);
const sourceStatus = ref(props.currentShow?.source?.status || 'offline');
const listeners = ref(props.initialListeners);
const provisioning = ref(props.initialProvisioning);
const streamPlayer = ref(null);
const isChatDrawerOpen = ref(false);
const isReconnecting = ref(false);
let hlsCheckInterval = null;
let hlsCheckAttempts = 0;
const maxHlsCheckAttempts = 15; // 30 seconds total (15 * 2 seconds)

// Computed properties
const showChatBox = computed(() => status.value !== 'offline' && activeShow.value?.status === 'live');
const showPlayer = computed(() => activeShow.value && activeShow.value.status === 'live' && hlsUrl.value && status.value === 'online' && sourceStatus.value === 'online' && provisioning.value === false && otherDevice.value === false && !isReconnecting.value);
const showTitle = computed(() => activeShow.value ? activeShow.value.title : 'No Show Active');
const otherLiveShows = computed(() => shows.value.filter(s => s.id !== activeShow.value?.id && s.status === 'live' && s.slug));
const upcomingShows = computed(() => shows.value.filter(s => s.id !== activeShow.value?.id && s.status === 'scheduled' && s.slug).slice(0, 3));

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

// Viewer tracking is now handled automatically by HLS playlist requests
// No need for separate heartbeat functionality

// HLS availability checker
const checkHlsAvailability = async () => {
    if (!hlsUrl.value) return false;
    
    try {
        // Try to fetch the HLS manifest with HEAD request
        const response = await fetch(hlsUrl.value, {
            method: 'HEAD',
            mode: 'cors',
            cache: 'no-cache'
        });
        
        return response.ok;
    } catch (error) {
        console.log('HLS check failed:', error);
        return false;
    }
};

const startHlsChecker = () => {
    hlsCheckAttempts = 0;
    
    // Clear any existing interval
    if (hlsCheckInterval) {
        clearInterval(hlsCheckInterval);
    }
    
    // Check immediately
    checkHlsAvailability().then(available => {
        if (available) {
            isReconnecting.value = false;
            stopHlsChecker();
        }
    });
    
    // Then check every 2 seconds
    hlsCheckInterval = setInterval(async () => {
        hlsCheckAttempts++;
        
        if (hlsCheckAttempts >= maxHlsCheckAttempts) {
            console.log('HLS check timeout - giving up');
            stopHlsChecker();
            isReconnecting.value = false;
            // Source might have gone offline again
            return;
        }
        
        const available = await checkHlsAvailability();
        if (available) {
            console.log('HLS is now available!');
            isReconnecting.value = false;
            stopHlsChecker();
        } else {
            console.log(`HLS not ready yet, attempt ${hlsCheckAttempts}/${maxHlsCheckAttempts}`);
        }
    }, 2000);
};

const stopHlsChecker = () => {
    if (hlsCheckInterval) {
        clearInterval(hlsCheckInterval);
        hlsCheckInterval = null;
    }
    hlsCheckAttempts = 0;
};

// Lifecycle
onMounted(() => {
    
    // Subscribe to source status updates if we have a source
    if (activeShow.value?.source_id) {
        Echo.channel(`source.${activeShow.value.source_id}`)
            .listen('.source.status.changed', (e) => {
                console.log('Source status changed:', e);
                const previousStatus = sourceStatus.value;
                sourceStatus.value = e.status;
                
                // Handle transitions to online from offline/error
                if (e.status === 'online' && ['offline', 'error'].includes(previousStatus)) {
                    console.log('Source transitioning to online - starting reconnection process');
                    isReconnecting.value = true;
                    startHlsChecker();
                    
                } else if (e.status === 'error') {
                    console.log('Source entered error state');
                    isReconnecting.value = false;
                    stopHlsChecker();
                } else if (e.status === 'offline') {
                    isReconnecting.value = false;
                    stopHlsChecker();
                }
            });
    }
    
    Echo.channel('StreamInfo')
        .listen('.stream.status.changed', (e) => {
            if (status.value === 'provisioning') {
                return false;
            }
            status.value = e.status;
            
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
                if (e.hlsUrl) {
                    hlsUrl.value = e.hlsUrl;
                }
            }
        })
        .listen('.show.source.changed', (e) => {
            // Handle source switching for a show
            if (activeShow.value && activeShow.value.id === e.show.id) {
                hlsUrl.value = e.hlsUrl;
                // Update source ID
                if (e.show.source_id && status.value === 'online') {
                    activeShow.value.source_id = e.show.source_id;
                }
            }
        });
    
    // Listen for show-specific events
    if (activeShow.value?.id) {
        Echo.channel(`show.${activeShow.value.id}`)
            .listen('.show.live', (e) => {
                console.log('Show went live:', e);
                
                // Update show data
                activeShow.value = {
                    ...activeShow.value,
                    id: e.id || activeShow.value.id,
                    title: e.title || activeShow.value.title,
                    slug: e.slug || activeShow.value.slug,
                    status: 'live',
                    source: e.source || activeShow.value.source,
                    source_id: e.source?.id || activeShow.value.source_id,
                    actual_start: e.actual_start || activeShow.value.actual_start
                };
                
                // Update stream and source status
                status.value = 'online';
                if (e.source?.status) {
                    sourceStatus.value = e.source.status;
                } else if (activeShow.value.source?.status) {
                    sourceStatus.value = activeShow.value.source.status;
                } else {
                    sourceStatus.value = 'online'; // Assume online if show is live
                }
                
                // Update HLS URL
                if (e.stream_url) {
                    hlsUrl.value = e.stream_url;
                } else if (e.hlsUrl) {
                    hlsUrl.value = e.hlsUrl;
                }
                
                // Subscribe to source updates if we have a new source
                if (e.source?.id && e.source.id !== activeShow.value.source_id) {
                    // Leave old source channel
                    if (activeShow.value.source_id) {
                        Echo.leave(`source.${activeShow.value.source_id}`);
                    }
                    
                    // Join new source channel
                    Echo.channel(`source.${e.source.id}`)
                        .listen('.source.status.changed', (sourceEvent) => {
                            console.log('Source status changed:', sourceEvent);
                            const previousStatus = sourceStatus.value;
                            sourceStatus.value = sourceEvent.status;
                            
                            // Handle transitions to online from offline/error
                            if (sourceEvent.status === 'online' && ['offline', 'error'].includes(previousStatus)) {
                                console.log('Source transitioning to online - starting reconnection process');
                                isReconnecting.value = true;
                                startHlsChecker();
                            } else if (sourceEvent.status === 'error') {
                                console.log('Source entered error state');
                                isReconnecting.value = false;
                                stopHlsChecker();
                            } else if (sourceEvent.status === 'offline') {
                                isReconnecting.value = false;
                                stopHlsChecker();
                            }
                        });
                }
            })
            .listen('.show.ended', (e) => {
                console.log('Show ended:', e);
                
                // Update show data
                activeShow.value = {
                    ...activeShow.value,
                    id: e.id || activeShow.value.id,
                    title: e.title || activeShow.value.title,
                    slug: e.slug || activeShow.value.slug,
                    status: 'ended',
                    actual_end: e.actual_end || activeShow.value.actual_end,
                    peak_viewer_count: e.peak_viewer_count || activeShow.value.peak_viewer_count
                };
                
                // Update stream status to offline when show ends
                status.value = 'offline';
                sourceStatus.value = 'offline';
                
                // Stop any reconnection attempts
                isReconnecting.value = false;
                stopHlsChecker();
            })
            .listen('.show.cancelled', (e) => {
                console.log('Show cancelled:', e);
                
                // Update show data
                activeShow.value = {
                    ...activeShow.value,
                    id: e.id || activeShow.value.id,
                    title: e.title || activeShow.value.title,
                    slug: e.slug || activeShow.value.slug,
                    status: 'cancelled'
                };
                
                // Update stream status to offline when show is cancelled
                status.value = 'offline';
                sourceStatus.value = 'offline';
                
                // Stop any reconnection attempts
                isReconnecting.value = false;
                stopHlsChecker();
            });
    }
});

// Cleanup on unmount
onUnmounted(() => {
    stopHlsChecker();
    
    // Leave the source channel
    if (activeShow.value?.source_id) {
        Echo.leave(`source.${activeShow.value.source_id}`);
    }
    
    // Leave the show channel
    if (activeShow.value?.id) {
        Echo.leave(`show.${activeShow.value.id}`);
    }
    
    // Leave the shows channel
    Echo.leave('shows');
    Echo.leave('StreamInfo');
});
</script>

<template>
    <div>
        <Head>
            <title>{{ showTitle }} - Stream</title>
        </Head>

        <div class="flex flex-col xl:flex-row xl:h-[calc(100vh-3rem)] xl:overflow-hidden">
            <!-- Livestream -->
            <div class="w-full flex-1 flex flex-col">
                <!-- Back to Shows Bar - Fixed at top -->
                <div class="bg-primary-900 border-b border-primary-800 px-4 py-2 flex-shrink-0">
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
                            <span v-if="activeShow?.source" class="text-primary-400 ml-2 hidden sm:inline">• {{ activeShow.source.name || activeShow.source }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- Mobile Chat Button -->
                            <button 
                                v-if="showChatBox"
                                @click="isChatDrawerOpen = true"
                                class="xl:hidden inline-flex items-center px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors relative"
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

                <!-- Scrollable Content Area -->
                <div class="flex-1 overflow-auto">
                    <div v-if="showPlayer">
                        <StreamPlayer ref="streamPlayer"
                                      :hls-url="hlsUrl"
                                      :show-info="activeShow"
                                      class="z-10 relative w-full bg-black mx-auto max-h-[60vh] sm:max-h-[70vh] md:max-h-[80vh] lg:max-h-[calc(100vh-12vh)]"></StreamPlayer>

                        <!-- Player Controls Bar -->
                    <div class="player-controls-bar bg-primary-900 border-t border-primary-800 px-4 py-2">
                        <div class="flex items-center justify-between">
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
                            <span class="text-primary-400">{{ activeShow.source.name || activeShow.source }}</span>
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
                    <!-- Show Status Pages -->
                    <div v-else-if="activeShow?.status === 'scheduled'">
                        <ShowScheduledStatusPage :show="activeShow" />
                    </div>
                    <div v-else-if="activeShow?.status === 'ended'">
                        <ShowEndedStatusPage 
                            :show="activeShow" 
                            :other-live-shows="otherLiveShows"
                            main-stream-url="/" />
                    </div>
                    <div v-else-if="activeShow?.status === 'cancelled'">
                        <ShowCancelledStatusPage 
                            :show="activeShow" 
                            :other-live-shows="otherLiveShows"
                            :upcoming-shows="upcomingShows"
                            main-stream-url="/schedule" />
                    </div>
                    <!-- Stream Status Pages -->
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
                    <div v-else-if="isReconnecting">
                        <StreamReconnectingStatusPage></StreamReconnectingStatusPage>
                    </div>
                    <div v-else-if="sourceStatus === 'error' && status === 'online'">
                        <StreamErrorStatusPage></StreamErrorStatusPage>
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
                            <span class="text-primary-400">{{ activeShow.source.name || activeShow.source }}</span>
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
            </div>
            <!-- Chat - Desktop Only -->
            <div v-if="showChatBox" class="hidden xl:block w-full xl:w-1/6 xl:min-w-[300px]">
                <ChatBox :rate-limit="rateLimit" :chat-messages="chatMessages"
                         class="h-full md:overflow-hidden"></ChatBox>
            </div>
        </div>
        
        <!-- Floating Chat Button for Mobile -->
        <button 
            v-if="showChatBox && showPlayer && !isChatDrawerOpen"
            @click="isChatDrawerOpen = true"
            class="xl:hidden fixed bottom-4 right-4 z-30 bg-primary-700 hover:bg-primary-600 text-white rounded-full p-4 shadow-lg transition-all duration-300 hover:scale-110"
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
