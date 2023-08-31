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
        this.notifyDeviceChange = Echo.private(`User.${userid}.StreamUrl`)
            .listen('.server.assignment.changed', (e) => {
                this.streamUrls = e.streamUrls;
                this.clientId = e.clientId;
                this.provisioning = e.provisioning;

                if (this.notifyDeviceChange !== null) {
                    this.notifyDeviceChange().unsubscribe()
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
            return false;
            var check = false;
            (function (a) {
                if (/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino|android|ipad|playbook|silk/i.test(a) || /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0, 4)))
                    check = true;
            })(navigator.userAgent || navigator.vendor || window.opera);
            return check;
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
        },
    },
    computed: {
        streamUrl() {
            if (this.streamUrls === null) {
                return null;
            }
            if (this.selectedStreamUrl === 'auto') {
                if (this.isMobile) {
                    return this.streamUrls.original;
                } else {
                    return this.streamUrls.original;
                }
            }
            return this.streamUrls[this.selectedStreamUrl];
        },
        showChatBox() {
            return this.status !== 'offline';
        },
        showPlayer() {
            return this.status === 'online' && this.provisioning === false && this.otherDevice === false;
        }
    },
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
