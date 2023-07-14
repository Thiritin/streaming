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
                if (this.status === 'provisioning') {
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
        }
    }
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
                                      :source="streamUrls.high + '&client=web'" type="flv" class="w-full rounded-lg"/>
                    </div>

                    <div class="max-w-7xl mx-auto" v-else-if="otherDevice === true && status === 'online'">
                        <div class="p-32">
                            <h1 class="text-3xl mb-3">End stream on other device?</h1>
                            <div class="mb-4">
                                To preserve bandwidth, we are only allowing once concurrent stream per user.
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
                                <label class="text-xs text-primary-200">Select your bitrate</label>
                                <select
                                    class="block w-full mt-1 rounded-md bg-white dark:bg-primary-700 border-primary-300 dark:border-primary-600 text-primary-900 dark:text-primary-100 focus:border-primary-300 dark:focus:border-primary-600 focus:ring focus:ring-primary-200 dark:focus:ring-primary-600 transition ease-in-out duration-150 sm:text-sm sm:leading-5">
                                    <option value="auto">Auto</option>
                                    <option value="high">1080p</option>
                                    <option value="medium">720p</option>
                                    <option value="low">480p</option>
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
