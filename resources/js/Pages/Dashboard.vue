<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head} from '@inertiajs/vue3';
import VueFlvPlayer from "@/Components/VueFlvPlayer.vue";
import FaWifiSlashIcon from "@/Components/Icons/FaWifiSlashIcon.vue";
import FaVideoIcon from "@/Components/Icons/FaVideoIcon.vue";
import FaIconUser from "@/Components/Icons/FaIconUser.vue";
import FaCircleNotchIcon from "@/Components/Icons/FaCircleNotchIcon.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";

export default {
    components: {
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
            .listen('.stream.url.changed', (e) => {
                this.streamUrls = e.streamUrls;
                if (this.status === 'provisioning' && e.streamUrls !== null) {
                    this.status = 'online';
                }
            });
    },
    props: {
        initialStreamUrls: {
            type: String,
            required: false,
        },
        initialOtherDevice: {
            type: Boolean,
            required: false,
        },
        initialStatus: {
            type: String,
            required: true,
        },
        initialListeners: {
            type: String,
            required: true,
        }
    },
    data() {
        return {
            mediaDataSource: {
                isLive: true,
            },
            otherDevice: this.initialOtherDevice,
            streamUrls: this.initialStreamUrls,
            status: this.initialStatus,
            listeners: this.initialListeners,
            selectedStreamUrl: 'auto'
        }
    },
    methods: {
        isMobile() {
            var check = false;
            (function(a){
                if(/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino|android|ipad|playbook|silk/i.test(a)||/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0,4)))
                    check = true;
            })(navigator.userAgent||navigator.vendor||window.opera);
            return check;
        }
    },
    computed : {
        streamUrl() {
            if (this.selectedStreamUrl === 'auto') {
                if (this.isMobile) {
                    return this.streamUrls.sd;
                } else {
                    return this.streamUrls.hd;
                }
            }
            return this.streamUrls[this.selectedStreamUrl];
        }
    },
}

</script>

<template>
    <Head title="Stream"/>

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="lg:max-w-7xl mx-auto">
                <div
                    class="mb-4 bg-white text-primary-900 dark:text-primary-100 text-center dark:bg-primary-800 overflow-hidden shadow-sm lg:rounded">
                    <div v-if="status === 'online' && otherDevice === false" class="rounded-lg">
                        <VueFlvPlayer :autoplay="true"
                                      :controls="true" :muted="false"
                                      ref="myPlayer"
                                      :media-data-source="mediaDataSource"
                                      :source="this.streamUrl + '&client=web'" type="flv" class="w-full rounded-lg"/>
                    </div>

                    <div class="max-w-7xl mx-auto" v-else-if="otherDevice === true && status === 'online'">
                        <div class="p-32">
                            <h1 class="text-3xl mb-3">End stream on other device?</h1>
                            <div class="mb-4">
                                To preserve bandwidth, we are only allowing one concurrent stream per user.
                            </div>
                            <primary-button @click="this.otherDevice = false">Continue here</primary-button>
                        </div>
                    </div>

                    <div class="max-w-7xl mx-auto" v-else-if="status === 'provisioning'">
                        <div class="p-32">
                            <FaCircleNotchIcon
                                class="animate-spin fill-current text-[6rem] block mx-auto mb-8"></FaCircleNotchIcon>
                            <h1 class="text-3xl mb-3">Finding a Server...</h1>
                            <div>
                                We are currently increasing the capacity of our datacenter. This may take a few
                                minutes
                            </div>
                            <div class="mt-4">
                                You will be automatically redirected once a server is available.
                            </div>
                        </div>
                    </div>

                    <div class="max-w-7xl mx-auto" v-else-if="status === 'starting_soon'">
                        <div class="p-32">
                            <h1 class="text-3xl mb-3">Starting soon</h1>
                            <div>
                                The stream will start in a few minutes.
                            </div>
                        </div>
                    </div>

                    <div class="max-w-7xl mx-auto" v-else-if="status === 'technical_issue'">
                        <div class="p-32">
                            <FaWifiSlashIcon class="fill-current text-[6rem] block mx-auto mb-8"></FaWifiSlashIcon>
                            <h1 class="text-3xl mb-3">Technical Issues</h1>
                            <div class="">There are currently technical
                                issues with the stream. Please stand by, this site will be automatically reloaded
                                once
                                the
                                stream is back online.
                            </div>
                            <div class="mt-4">
                                There are <span class="text-xl px-0.5">{{ listeners }}</span> people waiting for the
                                stream to come back online.
                            </div>
                            <div v-if="listeners > 200" class="text-xs text-primary-300">(Oh my... that is a lot of
                                people waiting)
                            </div>
                        </div>
                    </div>


                    <div class="max-w-7xl mx-auto" v-else-if="status === 'offline'">
                        <div class="p-32">
                            <FaVideoIcon class="fill-current text-[6rem] block mx-auto mb-8"></FaVideoIcon>
                            <h1 class="text-3xl text-primary-900 dark:text-primary-100 text-center">
                                The stream is currently offline.
                            </h1>
                            <p class="text-xs text-primary-300 mt-1">This page automatically reloads, once the
                                stream is
                                back online.</p>
                        </div>
                    </div>
                </div>


                <div class="bg-white dark:bg-primary-800 overflow-hidden shadow-sm lg:rounded"
                     v-if="status !== 'offline' && status !== 'provisioning'">
                    <div class="p-6 text-primary-900 dark:text-primary-200 flex justify-between">
                        <div class="max-w-screen-lg">
                            <div class="h1 text-2xl mb-4">Eurofurence 2023 Live</div>

                            <p class="mb-4">
                                🎉 This is the official Eurofurence stream, bringing the convention experience straight
                                to your hotel room! 🎉
                            </p>

                            <p class="mb-4">
                                Join us from the comfort of your own space and never miss a moment of the furry fun. We
                                have an exciting lineup of
                                major events planned just for you. Get ready to witness the epic Opening Ceremony,
                                filled with awe-inspiring
                                performances and surprises. Then, prepare to be dazzled by the spectacular Dance
                                Battles, where talented
                                fursuiters showcase their moves and compete for glory.
                            </p>

                            <p class="mb-4">
                                But that's not all! The Paw Pet Show is sure to melt your heart as adorable critters
                                take the stage to impress
                                and entertain. And of course, we couldn't forget about the Evening Dances, where the
                                party comes alive with
                                music, dancing, and the electric atmosphere that only Eurofurence can provide.
                            </p>

                            <p class="mb-4">
                                🚫 Please remember that this livestream is exclusively for our registered attendees. We
                                kindly request that you
                                do not share the stream with non-attendees, as it may violate GEMA restrictions.
                            </p>

                            <p class="mb-4">
                                So sit back, relax, and immerse yourself in the magic of Eurofurence 2023. Let's make
                                unforgettable memories
                                together! 🐾✨
                            </p>
                        </div>
                        <div class="min-w-[200px]">
                            <div class="flex gap-3 mb-5">
                                <div class="bg-primary-900 px-2 py-1 rounded-lg" v-if="status === 'online'">Live!</div>
                                <div class="flex items-center justify-center gap-2">
                                    <FaIconUser class="fill-current"></FaIconUser>
                                    {{ listeners }}
                                </div>
                            </div>
                            <div>
                                <!-- Select your Bitrate dropdown -->
                                <label class="text-xs text-primary-200">Select your resolution</label>
                                <select
                                    v-model="selectedStreamUrl"
                                    class="block w-full mt-1 rounded-md bg-white dark:bg-primary-700 border-primary-300 dark:border-primary-600 text-primary-900 dark:text-primary-100 focus:border-primary-300 dark:focus:border-primary-600 focus:ring focus:ring-primary-200 dark:focus:ring-primary-600 transition ease-in-out duration-150 sm:text-sm sm:leading-5">
                                    <option value="auto">Auto</option>
                                    <option value="fhd">1080p</option>
                                    <option value="hd">720p</option>
                                    <option value="sd">480p</option>
                                    <option value="ld">360p</option>
                                    <option value="audio_hd">Only Audio (HD)</option>
                                    <option value="audio_sd">Only Audio (SD)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
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
</style>
