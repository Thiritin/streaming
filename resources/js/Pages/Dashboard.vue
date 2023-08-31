<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head} from '@inertiajs/vue3';
import VueFlvPlayer from "@/Components/VueFlvPlayer.vue";
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
        FaCircleNotchIcon, FaIconUser, FaVideoIcon, FaWifiSlashIcon, VueFlvPlayer, Head, AuthenticatedLayout
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

        let userid = this.$page.props.auth.user.id;
        Echo.private(`User.${userid}.StreamUrl`)
            .listen('.server.assignment.changed', (e) => {
                this.streamUrls = e.streamUrls;
                this.clientId = e.clientId;
                this.provisioning = e.provisioning;

                if (this.notifyDeviceChange !== null) {
                    this.notifyDeviceChange.unsubscribe()
                }

                this.startNotifyDeviceChange();
            });

        this.startNotifyDeviceChange();
    },
    props: {
        initialStreamUrls: {
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
        initialClientId: {
            type: Number,
            required: false
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
            streamUrls: this.initialStreamUrls,
            status: this.initialStatus,
            listeners: this.initialListeners,
            selectedStreamUrl: 'auto',
            clientId: this.initialClientId,
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
        },
        startNotifyDeviceChange() {
            if (this.clientId)
                this.notifyDeviceChange = Echo.private('Client.' + this.clientId)
                    .listen('.otherDevice', (e) => {
                        this.otherDevice = true;
                    })
                    .listen('.disconnect', (e) => {
                        window.location.reload();
                    });
        }
        ,
    },
    computed: {
        streamUrl() {
            if (this.streamUrls === null) {
                return null;
            }
            if (this.selectedStreamUrl === 'auto') {
                if (this.shouldUseLowerResolution()) {
                    return this.streamUrls.sd;
                } else {
                    return this.streamUrls.fhd;
                }
            }
            return this.streamUrls[this.selectedStreamUrl];
        }
        ,
        showChatBox() {
            return this.status !== 'offline';
        }
        ,
        showPlayer() {
            return this.status === 'online' && this.provisioning === false && this.otherDevice === false;
        }
    }
    ,
}
</script>

<template>
    <Head>
        <title>Stream</title>
    </Head>

    <AuthenticatedLayout>
        <div class="flex flex-col md:flex-row pt-12 md:max-h-screen grow md:overflow-hidden">
            <!-- Livestream -->
            <div class="w-full flex-1 overflow-auto">
                <div v-if="showPlayer">
                    <StreamPlayer :stream-url="streamUrl"
                                  class="z-10 relative w-full bg-black max-h-[calc(100vh_-_10vh)]"></StreamPlayer>
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

<style scoped>
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
