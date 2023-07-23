<script setup>

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import FaWifiSlashIcon from "@/Components/Icons/FaWifiSlashIcon.vue";
import VueFlvPlayer from "@/Components/VueFlvPlayer.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import {Head} from "@inertiajs/vue3";

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    streamUrls: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <authenticated-layout>
        <Head>
            <title>External Stream</title>
        </Head>
        <div class="py-12">
            <div class="lg:max-w-7xl mx-auto">
                <div
                    class="mb-4  text-primary-100 bg-primary-800 overflow-hidden shadow-sm lg:rounded p-6">
                    <h1 class="h1 text-2xl font-semibold">Enjoy the stream on any device!</h1>
                    <p>To make it easy for everyone to display the stream on any device you can use our provided link to
                        connect it to various software.</p>
                </div>
                <div
                    class="mb-4 bg-primary-800 text-primary-100 overflow-hidden shadow-sm lg:rounded p-6">
                    <div v-if="status !== 'offline'">
                        <h2 class="h2 text-xl font-semibold">Choose your stream quality:</h2>
                        <div class="my-4">
                            <label for="stream-quality-fhd"
                                   class="text-primary-100 block text-gray-700 text-sm font-bold mb-2">Full HD
                                (1080p):</label>
                            <input id="stream-quality-fhd" class="text-primary-900 form-input block w-full mt-1"
                                   type="text" readonly :value="streamUrls.fhd + '&client=vlc'">
                        </div>
                        <div class="my-4">
                            <label for="stream-quality-hd"
                                   class="text-primary-100 block text-gray-700 text-sm font-bold mb-2">HD
                                (720p):</label>
                            <input id="stream-quality-hd" class="text-primary-900 form-input block w-full mt-1"
                                   type="text" readonly :value="streamUrls.hd + '&client=vlc'">
                        </div>
                        <div class="my-4">
                            <label for="stream-quality-sd"
                                   class="text-primary-100 block text-gray-700 text-sm font-bold mb-2">SD
                                (480p):</label>
                            <input id="stream-quality-sd" class="text-primary-900 form-input block w-full mt-1"
                                   type="text" readonly :value="streamUrls.sd + '&client=vlc'">
                        </div>
                        <div class="my-4">
                            <label for="stream-quality-low"
                                   class="text-primary-100 block text-gray-700 text-sm font-bold mb-2">LD
                                (320p):</label>
                            <input id="stream-quality-" class="text-primary-900 form-input block w-full mt-1"
                                   type="text" readonly :value="streamUrls.ld + '&client=vlc'">
                        </div>
                        <div class="my-4">
                            <label for="stream-quality-low"
                                   class="text-primary-100 block text-gray-700 text-sm font-bold mb-2">Audio HD
                                (420p):</label>
                            <input id="stream-quality-" class="text-primary-900 form-input block w-full mt-1"
                                   type="text" readonly :value="streamUrls.audio_hd + '&client=vlc'">
                        </div>
                        <div class="my-4">
                            <label for="stream-quality-low"
                                   class="text-primary-100 block text-gray-700 text-sm font-bold mb-2">Audio SD
                                (420p):</label>
                            <input id="stream-quality-" class="text-primary-900 form-input block w-full mt-1"
                                   type="text" readonly :value="streamUrls.audio_sd + '&client=vlc'">
                        </div>
                    </div>
                    <div v-else>
                        <h2 class="h2 text-xl font-semibold">There are currently no streams available. Check back later.</h2>
                    </div>
                </div>
                <div
                    class="mb-4 bg-primary-800 text-primary-100 overflow-hidden shadow-sm lg:rounded p-6">
                    <h2 class="text-xl mb-2 font-semibold">1. Open Your Streaming Client</h2>
                    <p class="mb-3">Start by opening your streaming client on your device.</p>
                    <img class="mb-8 rounded-xl" src="../../assets/open-streaming-client.png"
                         alt="Open Streaming Client">

                    <h2 class="text-xl mb-2 font-semibold">2. Find the Stream Input Option</h2>
                    <p class="mb-3">In your streaming client, find the option to input a stream link. This is often
                        found
                        under a "File" or "Stream" menu.</p>
                    <img class="mb-8 rounded-xl" src="../../assets/find-stream-input.png" alt="Find Stream Input">

                    <h2 class="text-xl mb-2 font-semibold">3. Enter Your Stream Link</h2>
                    <p class="mb-3">In the provided input field, paste the .flv stream link that you got from our
                        platform.</p>
                    <img class="mb-8 rounded-xl" src="../../assets/enter-stream-link.png" alt="Enter Stream Link">

                    <h2 class="text-xl mb-2 font-semibold">4. Confirm the Stream</h2>
                    <p class="mb-3">Confirm and save the input to start streaming. Your stream should start playing in
                        the
                        streaming client.</p>
                    <img class="mb-8 rounded-xl" src="../../assets/confirm-stream.png" alt="Confirm Stream">

                    <h2 class="text-xl mb-2 font-semibold">5. Enjoy Your Stream!</h2>
                    <p class="mb-3">Now your stream should be playing on your streaming client. Enjoy!</p>
                </div>
            </div>
        </div>
    </authenticated-layout>
</template>

<style scoped>

</style>
