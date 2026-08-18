<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { useRealtimeResync } from '@/composables/useRealtimeResync';
import { useChat } from '@/composables/useChat';
import StreamPlayer from "@/Components/Livestream/StreamPlayer.vue";
import BoopButton from "@/Components/Livestream/BoopButton.vue";
import ChatPanel from "@/Components/Chat/ChatPanel.vue";
import MarkdownText from "@/Components/MarkdownText.vue";
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
    /**
     * Where to send a viewer whose show is not watchable: the primary channel if live,
     * otherwise the busiest live show, otherwise what is on next.
     */
    promoted: {
        type: Object,
        required: false,
        default: null
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
    chatSettings: {
        type: Object,
        required: false,
        default: () => ({})
    },
    chatState: {
        type: Object,
        required: false,
        default: () => ({})
    },
    sourceId: {
        type: [Number, String],
        required: true
    }
});

const page = usePage();

/*
 * Owned by the page rather than by ChatPanel. The panel is mounted and unmounted
 * as chat is hidden, popped into the mobile drawer and closed again; when the log
 * lived inside it, every one of those threw the messages away and reseeded from
 * the page-load snapshot, so reopening chat showed an empty room. Here it lives
 * as long as the page does and keeps receiving while chat is out of sight.
 */
const chat = useChat({
    sourceId: props.sourceId,
    initialMessages: props.chatMessages ?? [],
    initialSettings: props.chatSettings,
    initialState: props.chatState,
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
const isTheaterMode = ref(false);
const isChatHidden = ref(false);
const isChromeVisible = ref(true);
let chromeTimer = null;
const CHROME_IDLE_MS = 2600;
let hlsCheckInterval = null;
let hlsCheckAttempts = 0;
const maxHlsCheckAttempts = 15; // 30 seconds total (15 * 2 seconds)

/*
 * Theater mode: the video fills the window and the chrome floats on top of it,
 * fading out once the pointer settles so there is nothing on screen but the
 * stream. Moving the mouse, typing or touching brings it straight back.
 *
 * Chat is deliberately not part of the chrome. A chat you chose to open is not
 * a distraction, and one that faded out mid-message would be a bug.
 */
const toggleTheaterMode = () => {
    isTheaterMode.value = !isTheaterMode.value;
    localStorage.setItem('theaterMode', isTheaterMode.value ? 'true' : 'false');

    revealChrome();
};

const revealChrome = () => {
    isChromeVisible.value = true;
    clearTimeout(chromeTimer);

    if (!isTheaterMode.value) return;

    chromeTimer = setTimeout(() => {
        // The drawer sits on top of the video anyway, and the bar it was opened
        // from is the way back out of it.
        if (isChatDrawerOpen.value) {
            revealChrome();

            return;
        }

        isChromeVisible.value = false;
    }, CHROME_IDLE_MS);
};

// Hovering the bars is intent to use them, so they stay put until the pointer
// leaves again.
const holdChrome = () => {
    isChromeVisible.value = true;
    clearTimeout(chromeTimer);
};

const toggleChatVisibility = () => {
    isChatHidden.value = !isChatHidden.value;
    localStorage.setItem('chatHidden', isChatHidden.value ? 'true' : 'false');
};

const handleKeydown = (e) => {
    revealChrome();

    if (e.key === 'Escape' && isTheaterMode.value) {
        toggleTheaterMode();
    }
};

// Load theater mode preference from localStorage
const loadTheaterModePreference = () => {
    if (localStorage.getItem('theaterMode') === 'true') {
        isTheaterMode.value = true;
        revealChrome();
    }

    isChatHidden.value = localStorage.getItem('chatHidden') === 'true';
};

// Computed properties
const chatEnabled = computed(() => page.props.features?.chat !== false);
const boopsEnabled = computed(() => page.props.features?.boops !== false);
const showChatBox = computed(() => chatEnabled.value && status.value !== 'offline' && activeShow.value?.status === 'live');
const showPlayer = computed(() => activeShow.value && activeShow.value.status === 'live' && hlsUrl.value && status.value === 'online' && sourceStatus.value === 'online' && provisioning.value === false && otherDevice.value === false && !isReconnecting.value);
const showTitle = computed(() => activeShow.value ? activeShow.value.title : 'No Show Active');
const otherLiveShows = computed(() => shows.value.filter(s => s.id !== activeShow.value?.id && s.status === 'live' && s.slug));

// Methods
const isMobile = () => {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
};

const openChatPopout = () => {
    if (!activeShow.value?.slug) return;
    const url = route('show.chat', activeShow.value.slug);
    window.open(url, 'chat_popout', 'width=400,height=600,resizable=yes,scrollbars=yes');
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

// Source channel subscription. Kept in one place so the show can move to another
// source mid-session without losing status updates.
let subscribedSourceId = null;

const handleSourceStatus = (e) => {
    const previousStatus = sourceStatus.value;
    sourceStatus.value = e.status;

    if (e.status === 'online' && ['offline', 'error'].includes(previousStatus)) {
        isReconnecting.value = true;
        startHlsChecker();
    } else if (e.status === 'error' || e.status === 'offline') {
        isReconnecting.value = false;
        stopHlsChecker();
    }
};

const subscribeToSource = (sourceId) => {
    if (!sourceId || sourceId === subscribedSourceId) return;

    if (subscribedSourceId) {
        Echo.leave(`source.${subscribedSourceId}`);
    }

    subscribedSourceId = sourceId;
    Echo.channel(`source.${sourceId}`).listen('.source.status.changed', handleSourceStatus);
};

// Pull the server's view of the show back after a websocket gap; the refs below
// keep the page in step with the props that come back.
const resync = () => {
    router.reload({
        only: ['currentShow', 'availableShows', 'initialStatus', 'initialHlsUrl', 'initialListeners'],
    });
};

useRealtimeResync(resync);

watch(() => props.currentShow, (show) => {
    if (!show) return;

    activeShow.value = show;
    sourceStatus.value = show.source?.status || 'offline';
    subscribeToSource(show.source_id);
}, { deep: true });

// Closing the drawer restarts the idle countdown that it was holding open.
watch(isChatDrawerOpen, () => revealChrome());

watch(() => props.availableShows, (value) => shows.value = value ?? []);
watch(() => props.initialStatus, (value) => status.value = value);
watch(() => props.initialHlsUrl, (value) => { if (value) hlsUrl.value = value; });
watch(() => props.initialListeners, (value) => listeners.value = value);

// Lifecycle
onMounted(() => {
    // Load theater mode preference and add keyboard listener
    loadTheaterModePreference();
    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('mousemove', revealChrome, { passive: true });
    window.addEventListener('mousedown', revealChrome, { passive: true });
    window.addEventListener('touchstart', revealChrome, { passive: true });

    subscribeToSource(activeShow.value?.source_id);

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
                
                subscribeToSource(activeShow.value.source_id);
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

    // Clean up theater mode
    clearTimeout(chromeTimer);
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('mousemove', revealChrome);
    window.removeEventListener('mousedown', revealChrome);
    window.removeEventListener('touchstart', revealChrome);

    // Leave the source channel
    if (subscribedSourceId) {
        Echo.leave(`source.${subscribedSourceId}`);
        subscribedSourceId = null;
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

        <div
            class="flex flex-col lg:flex-row lg:overflow-hidden transition-all duration-(--dur-base)"
            :class="isTheaterMode ? 'fixed inset-0 z-50 bg-black' : 'xl:h-[calc(100vh-3rem)]'"
        >
            <!-- Livestream -->
            <div class="w-full flex-1 flex flex-col relative min-h-0" :class="isTheaterMode && !isChromeVisible ? 'cursor-none' : ''">
                <!-- Back to Shows Bar. In theater mode it floats over the video and
                     fades with the rest of the chrome instead of taking a row. -->
                <div
                    class="px-4 py-2 flex-shrink-0 transition-opacity duration-(--dur-base)"
                    :class="[
                        isTheaterMode
                            ? 'absolute top-0 inset-x-0 z-30 bg-gradient-to-b from-black/85 via-black/45 to-transparent'
                            : 'bg-primary-900 border-b border-primary-800',
                        isTheaterMode && !isChromeVisible ? 'opacity-0 pointer-events-none' : 'opacity-100',
                    ]"
                    @mouseenter="holdChrome"
                    @mouseleave="revealChrome"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center min-w-0">
                            <Link :href="route('shows.grid')" class="inline-flex items-center shrink-0 text-primary-400 hover:text-white transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                                </svg>
                                <span class="hidden sm:inline">Back to Shows</span>
                                <span class="sm:hidden">Back</span>
                            </Link>
                            <span class="mx-3 text-primary-600 hidden sm:inline">|</span>
                            <span class="text-white font-semibold ml-3 sm:ml-0 truncate min-w-0">{{ showTitle }}</span>
                            <span v-if="activeShow?.source" class="text-primary-400 ml-2 hidden sm:inline">• {{ activeShow.source.name || activeShow.source }}</span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <!-- Mobile Chat Button -->
                            <button 
                                v-if="showChatBox"
                                @click="isChatDrawerOpen = true"
                                class="lg:hidden inline-flex items-center px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors relative"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                </svg>
                                <span class="ml-1">Chat</span>
                                <!-- Unread indicator -->
                                <span v-if="chatMessages?.length > 0" class="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                            </button>
                            
                            <!-- Show Chat (desktop, when collapsed) -->
                            <button
                                v-if="showChatBox && isChatHidden"
                                @click="toggleChatVisibility"
                                class="hidden lg:inline-flex items-center px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors"
                                title="Show chat"
                            >
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                                <span>Chat</span>
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

                <!-- Scrollable Content Area. Theater mode keeps the video alone on
                     screen, so nothing below it is rendered and nothing scrolls. -->
                <div
                    class="flex-1"
                    :class="[
                        isTheaterMode ? 'overflow-hidden flex items-center justify-center' : 'overflow-auto',
                        // Without a player the column is short, and the leftover height of
                        // the viewport-tall layout used to sit under the page as a dead
                        // band. Stacking it as a flex column lets the status screen take
                        // that space instead.
                        !isTheaterMode && !showPlayer ? 'flex flex-col' : '',
                    ]"
                >
                    <div v-if="showPlayer" :class="isTheaterMode ? 'w-full' : ''">
                        <!-- Landing half of the shared-element morph: the tile that
                             was clicked on the browse grid tweens into this box.
                             See composables/useMediaHero.js. -->
                        <StreamPlayer ref="streamPlayer"
                                      v-media-hero
                                      :hls-url="hlsUrl"
                                      :show-info="activeShow"
                                      class="relative w-full bg-black mx-auto overflow-hidden"
                                      :class="isTheaterMode ? 'h-full' : 'h-[min(56.25vw,70vh)]'"></StreamPlayer>

                        <!-- Player Controls Bar. Floats over the foot of the video in
                             theater mode, on the same fade as the top bar. -->
                    <div
                        class="player-controls-bar px-4 py-2 transition-opacity duration-(--dur-base)"
                        :class="[
                            isTheaterMode
                                ? 'absolute bottom-0 inset-x-0 z-30 bg-gradient-to-t from-black/85 via-black/45 to-transparent'
                                : 'bg-primary-900 border-t border-primary-800',
                            isTheaterMode && !isChromeVisible ? 'opacity-0 pointer-events-none' : 'opacity-100',
                        ]"
                        @mouseenter="holdChrome"
                        @mouseleave="revealChrome"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2 text-sm text-primary-400 min-w-0">
                                <span class="truncate">{{ listeners }} viewers</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <!-- Boops: shared counter, no sign-in, no undo. -->
                                <BoopButton
                                    v-if="boopsEnabled && activeShow?.id && activeShow?.slug"
                                    :show-id="activeShow.id"
                                    :show-slug="activeShow.slug"
                                    :initial-count="activeShow.boop_count ?? 0"
                                    :disabled="activeShow.status !== 'live'"
                                />

                                <!-- Theater Mode Toggle -->
                                <button
                                    @click="toggleTheaterMode"
                                    class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 text-sm rounded transition-colors"
                                    :class="isTheaterMode ? 'bg-primary-500 text-white' : 'bg-primary-800 text-primary-300 hover:bg-primary-700 hover:text-white'"
                                    :title="isTheaterMode ? 'Exit Theater Mode (Esc)' : 'Theater Mode'"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path v-if="!isTheaterMode" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V4.5M9 9H4.5M9 9L3.5 3.5M9 15v4.5M9 15H4.5M9 15l-5.5 5.5M15 9h4.5M15 9V4.5M15 9l5.5-5.5M15 15h4.5M15 15v4.5m0-4.5l5.5 5.5"/>
                                    </svg>
                                    <span class="hidden md:inline">{{ isTheaterMode ? 'Exit' : 'Theater' }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Stream Information -->
                    <Container v-if="!isTheaterMode" class="bg-primary-800 border-t-2 border-primary-700" padding="p-6">
                        <h2 class="text-2xl font-bold text-white mb-3">{{ activeShow?.title || 'Stream' }}</h2>
                        <MarkdownText
                            v-if="activeShow?.description"
                            :html="activeShow.description_html"
                            :text="activeShow.description"
                            class="text-primary-200 text-lg leading-relaxed mb-4"
                        />
                        <div v-if="activeShow?.source" class="flex items-center gap-2 text-sm">
                            <span class="font-semibold text-primary-300">Source:</span>
                            <span class="text-primary-400">{{ activeShow.source.name || activeShow.source }}</span>
                        </div>
                    </Container>

                    <!-- Other Live Shows -->
                    <Container v-if="otherLiveShows.length > 0 && !isTheaterMode" class="bg-black/50 border-t border-primary-800" padding="p-6">
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
                    <div v-else-if="activeShow?.status === 'scheduled'" class="flex flex-1">
                        <ShowScheduledStatusPage :show="activeShow" :promoted="promoted" />
                    </div>
                    <div v-else-if="activeShow?.status === 'ended'" class="flex flex-1">
                        <ShowEndedStatusPage :show="activeShow" :promoted="promoted" />
                    </div>
                    <div v-else-if="activeShow?.status === 'cancelled'" class="flex flex-1">
                        <ShowCancelledStatusPage :show="activeShow" :promoted="promoted" />
                    </div>
                    <!-- Stream Status Pages -->
                    <div v-else-if="status === 'starting_soon'" class="flex flex-1">
                        <StreamStartingSoonStatusPage :show="activeShow" />
                    </div>
                    <div v-else-if="provisioning === true && status !== 'offline'" class="flex flex-1">
                        <StreamProvisioningStatusPage :show="activeShow" />
                    </div>
                    <div v-else-if="otherDevice === true && status !== 'offline'" class="flex flex-1">
                        <StreamOtherDeviceStatusPage
                            @endStreamOnOtherDevice="otherDevice = false"></StreamOtherDeviceStatusPage>
                    </div>
                    <div v-else-if="status === 'technical_issue'" class="flex flex-1">
                        <StreamTechnicalIssuesStatusPage :listeners="listeners" :show="activeShow" :promoted="promoted" />
                    </div>
                    <div v-else-if="isReconnecting" class="flex flex-1">
                        <StreamReconnectingStatusPage :show="activeShow" />
                    </div>
                    <div v-else-if="sourceStatus === 'error' && status === 'online'" class="flex flex-1">
                        <StreamErrorStatusPage :show="activeShow" :promoted="promoted" />
                    </div>
                    <div v-else class="flex flex-1">
                        <StreamOfflineStatusPage :show="activeShow" :promoted="promoted" />
                    </div>
                    
                    <!-- Stream Information for non-player states -->
                    <Container v-if="!showPlayer && activeShow" class="bg-primary-800 border-t-2 border-primary-700" padding="p-6">
                        <h2 class="text-2xl font-bold text-white mb-3">{{ activeShow.title }}</h2>
                        <MarkdownText
                            v-if="activeShow.description"
                            :html="activeShow.description_html"
                            :text="activeShow.description"
                            class="text-primary-200 text-lg leading-relaxed mb-4"
                        />
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
            <div v-if="showChatBox && !isChatHidden" class="hidden lg:flex lg:flex-col w-full lg:w-1/6 lg:min-w-[300px]">
                <!-- Chat Header with Pop-out Button -->
                <div class="bg-primary-950 border-b border-primary-800 px-3 py-2 flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-1">
                        <button
                            @click="toggleChatVisibility"
                            class="p-1.5 -ml-1.5 text-primary-400 hover:text-white hover:bg-primary-800 rounded transition-colors"
                            title="Hide chat"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                        <span class="text-white font-semibold text-sm">Chat</span>
                    </div>
                    <button
                        @click="openChatPopout"
                        class="p-1.5 text-primary-400 hover:text-white hover:bg-primary-800 rounded transition-colors"
                        title="Pop out chat"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </button>
                </div>
                <ChatPanel
                    :chat="chat"
                    :show-header="false"
                    :source-id="sourceId"
                    class="flex-1 overflow-hidden"
                />
            </div>
        </div>
        
        <!-- Floating Chat Button for Mobile -->
        <button 
            v-if="showChatBox && showPlayer && !isChatDrawerOpen"
            @click="isChatDrawerOpen = true"
            class="lg:hidden fixed bottom-4 right-4 z-30 bg-primary-700 hover:bg-primary-600 text-white rounded-full p-4 shadow-lg transition-all duration-(--dur-base) hover:scale-110"
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
            :overlay="false"
            panel-class="bg-transparent"
            header-class="bg-primary-950/45 backdrop-blur-md [text-shadow:0_1px_3px_rgb(0_0_0/0.9)]"
        >
            <template #header>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-white">Live Chat</h2>
                    <button
                        @click="openChatPopout"
                        class="p-1.5 text-primary-400 hover:text-white hover:bg-primary-800 rounded transition-colors"
                        title="Pop out chat"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </button>
                </div>
            </template>
            <ChatPanel
                v-if="showChatBox && isChatDrawerOpen"
                :chat="chat"
                :show-header="false"
                :source-id="sourceId"
                transparent
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
