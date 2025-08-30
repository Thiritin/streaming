<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head, router, Link} from '@inertiajs/vue3';
import FaWifiSlashIcon from "@/Components/Icons/FaWifiSlashIcon.vue";
import FaVideoIcon from "@/Components/Icons/FaVideoIcon.vue";
import FaIconUser from "@/Components/Icons/FaIconUser.vue";
import FaCircleNotchIcon from "@/Components/Icons/FaCircleNotchIcon.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import StreamPlayer from "@/Components/Livestream/StreamPlayer.vue";
import ChatBox from "@/Components/Livestream/ChatBox.vue";
import StreamInfoBox from "@/Components/Content/StreamInfoBox.vue";
import StreamOfflineStatusPage from "@/Components/Livestream/StatusPages/StreamOfflineStatusPage.vue";
import StreamProvisioningStatusPage from "@/Components/Livestream/StatusPages/StreamProvisioningStatusPage.vue";
import StreamOtherDeviceStatusPage from "@/Components/Livestream/StatusPages/StreamOtherDeviceStatusPage.vue";
import StreamTechnicalIssuesStatusPage from "@/Components/Livestream/StatusPages/StreamTechnicalIssuesStatusPage.vue";
import StreamStartingSoonStatusPage from "@/Components/Livestream/StatusPages/StreamStartingSoonStatusPage.vue";

export default {
    components: {
        StreamStartingSoonStatusPage,
        StreamTechnicalIssuesStatusPage,
        StreamOtherDeviceStatusPage,
        StreamProvisioningStatusPage,
        StreamOfflineStatusPage,
        StreamInfoBox,
        ChatBox,
        StreamPlayer,
        GuestLayout,
        PrimaryButton,
        FaCircleNotchIcon, FaIconUser, FaVideoIcon, FaWifiSlashIcon, Head, Link, AuthenticatedLayout
    },
    setup() {
        return {
            animated: false,
        }
    },
    mounted() {
        this.animated = true;
        Echo.channel('StreamInfo')
            .listen('.stream.status.changed', (e) => {
                if (this.status === 'provisioning') {
                    return false;
                }
                this.status = e.status;
            })
            .listen('.stream.listeners.changed', (e) => {
                this.listeners = e.listeners;
            });

        // Listen for show updates
        Echo.channel('shows')
            .listen('.show.status.changed', (e) => {
                // Update show status in the list
                const showIndex = this.shows.findIndex(s => s.id === e.show.id);
                if (showIndex !== -1) {
                    // Preserve slug if not provided in the event
                    this.shows[showIndex] = {
                        ...this.shows[showIndex], 
                        ...e.show,
                        slug: e.show.slug || this.shows[showIndex].slug
                    };
                }

                // If it's the active show, update HLS URLs
                if (this.activeShow && this.activeShow.id === e.show.id) {
                    this.activeShow = {
                        ...this.activeShow,
                        ...e.show,
                        slug: e.show.slug || this.activeShow.slug
                    };
                    if (e.hlsUrls) {
                        this.hlsUrls = e.hlsUrls;
                    }
                }
            })
            .listen('.show.source.changed', (e) => {
                // Handle source switching for a show
                if (this.activeShow && this.activeShow.id === e.show.id) {
                    this.hlsUrls = e.hlsUrls;
                }
            });
    },
    props: {
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
    },
    data() {
        return {
            otherDevice: this.initialOtherDevice,
            activeShow: this.currentShow,
            shows: this.availableShows,
            hlsUrls: this.initialHlsUrls,
            status: this.initialStatus,
            listeners: this.initialListeners,
            provisioning: this.initialProvisioning,
            notifyDeviceChange: null,
        }
    },
    methods: {
        isMobile() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        },
        shouldUseLowerResolution() {
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
            return this.isMobile();
        }
    },
    computed: {
        showChatBox() {
            return this.status !== 'offline';
        }
        ,
        showPlayer() {
            return this.activeShow && this.hlsUrls && this.status === 'online' && this.provisioning === false && this.otherDevice === false;
        },
        showTitle() {
            return this.activeShow ? this.activeShow.title : 'No Show Active';
        }
    }
    ,
}
</script>

<template>
    <Head>
        <title>{{ showTitle }} - Stream</title>
    </Head>

    <AuthenticatedLayout>
        <!-- Back to Shows Button -->
        <div class="bg-primary-900 border-b border-primary-800 px-4 py-2">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <Link :href="route('shows.grid')" class="inline-flex items-center text-primary-400 hover:text-white transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back to Shows
                    </Link>
                    <span class="mx-3 text-primary-600">|</span>
                    <span class="text-white font-semibold">{{ showTitle }}</span>
                    <span v-if="activeShow?.source" class="text-primary-400 ml-2">• {{ activeShow.source }}</span>
                </div>
                <Link
                    v-if="activeShow && activeShow.slug"
                    :href="route('show.external', activeShow.slug)"
                    class="inline-flex items-center px-3 py-1 text-sm bg-primary-800 hover:bg-primary-700 text-primary-300 hover:text-white rounded transition-colors"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    External Player
                </Link>
            </div>
        </div>

        <div class="flex flex-col md:flex-row md:max-h-[calc(100vh-3rem)] grow md:overflow-hidden">
            <!-- Livestream -->
            <div class="w-full flex-1 overflow-auto">
                <div v-if="showPlayer">
                    <StreamPlayer :hls-urls="hlsUrls"
                                  :show-info="activeShow"
                                  class="z-10 relative w-full bg-black max-h-[calc(100vh_-_10vh)]"></StreamPlayer>

                    <!-- Other Available Shows -->
                    <div v-if="shows.length > 1" class="show-selector p-3 bg-primary-800 border-t border-primary-700">
                        <div class="text-sm text-primary-400 mb-2">Other Shows:</div>
                        <div class="flex flex-wrap gap-2">
                            <Link v-for="show in shows.filter(s => s.id !== activeShow?.id && s.slug)"
                                  :key="show.id"
                                  :href="route('show.view', show.slug)"
                                  class="px-3 py-1 bg-primary-700 hover:bg-primary-600 rounded text-sm text-white transition-colors"
                                  :class="{'opacity-50 cursor-not-allowed': !show.can_watch}">
                                {{ show.title }}
                                <span v-if="show.status === 'live'" class="ml-1 text-red-400">● LIVE</span>
                            </Link>
                        </div>
                    </div>
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
                <StreamOfflineStatusPage v-else></StreamOfflineStatusPage>
                <div>
                    <StreamInfoBox v-if="showChatBox"
                                   :stream-url="selectedStreamUrl"
                                   @stream-url-changed="selectedStreamUrl = $event"
                                   :listeners="listeners"></StreamInfoBox>
                </div>
            </div>
            <!-- Chat -->
            <div v-if="showChatBox" class="w-full md:w-1/6 md:min-w-[300px]">
                <ChatBox :rate-limit="rateLimit" :chat-messages="chatMessages"
                         class="h-[600px] md:h-[calc(100vh_-_3rem)] md:overflow-hidden grow"></ChatBox>
            </div>
        </div>
    </AuthenticatedLayout>
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
