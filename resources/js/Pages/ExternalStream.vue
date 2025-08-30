<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {Head, Link} from "@inertiajs/vue3";
import Container from "@/Components/Container.vue";
import Input from "@/Components/ui/Input.vue";
import Button from "@/Components/ui/Button.vue";

const props = defineProps({
    show: {
        type: Object,
        required: true,
    },
});

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        // Could add a toast notification here
    });
};
</script>

<template>
    <authenticated-layout>
        <Head>
            <title>{{ show.title }} - External Player</title>
        </Head>

        <!-- Header with Back Button -->
        <div class="bg-primary-900 border-b border-primary-800 py-2">
            <Container>
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <Link :href="route('show.view', show.slug)" class="inline-flex items-center text-primary-400 hover:text-white transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back to Player
                    </Link>
                    <span class="mx-3 text-primary-600">|</span>
                    <Link :href="route('shows.grid')" class="text-primary-400 hover:text-white transition-colors">
                        All Shows
                    </Link>
                </div>
            </div>
            <div class="mt-2">
                <h1 class="text-white font-semibold text-lg">External Player URLs: {{ show.title }}</h1>
                <span v-if="show.source" class="text-primary-400">{{ show.source }}</span>
                <span :class="{
                    'text-green-400': show.status === 'live',
                    'text-yellow-400': show.status === 'scheduled',
                    'text-red-400': show.status === 'ended'
                }" class="ml-3 text-sm">● {{ show.status.toUpperCase() }}</span>
            </div>
            </Container>
        </div>

        <Container class="py-8">
                <!-- Introduction -->
                <div class="mb-6 text-primary-100 bg-primary-800 overflow-hidden shadow-sm lg:rounded p-6">
                    <h2 class="text-2xl font-semibold mb-2">Watch on Any Device</h2>
                    <p>Use these HLS stream URLs to watch "{{ show.title }}" in your preferred media player like VLC, MPV, or any HLS-compatible application.</p>
                </div>

                <!-- Stream URLs -->
                <div class="mb-6 bg-primary-800 text-primary-100 overflow-hidden shadow-sm lg:rounded p-6">
                    <div v-if="show.can_watch && show.hls_urls">
                        <h3 class="text-xl font-semibold mb-4">Available Stream Qualities</h3>

                        <!-- Master Playlist -->
                        <div class="mb-4 p-4 bg-primary-900 rounded">
                            <label class="text-primary-200 block text-sm font-bold mb-2">
                                🎯 Master Playlist (Recommended - Adaptive Bitrate)
                            </label>
                            <div class="flex gap-2">
                                <Input
                                    :modelValue="show.hls_urls.master"
                                    readonly
                                    class="flex-1 font-mono text-sm"
                                    @click="$event.target.select()"
                                />
                                <Button
                                    @click="copyToClipboard(show.hls_urls.master)"
                                    size="sm"
                                >
                                    Copy
                                </Button>
                            </div>
                            <p class="text-xs text-primary-400 mt-1">Automatically adjusts quality based on your connection</p>
                        </div>

                        <!-- Individual Qualities -->
                        <details class="mt-4">
                            <summary class="cursor-pointer text-primary-300 hover:text-primary-200 mb-3">
                                Show individual quality streams →
                            </summary>

                            <div class="space-y-3 mt-3">
                                <!-- Full HD -->
                                <div v-if="show.hls_urls.fhd" class="flex gap-2 items-center">
                                    <label class="text-primary-300 text-sm font-semibold w-24">1080p FHD:</label>
                                    <Input
                                        :modelValue="show.hls_urls.fhd"
                                        readonly
                                        class="flex-1 font-mono text-xs h-8"
                                        @click="$event.target.select()"
                                    />
                                    <Button
                                        @click="copyToClipboard(show.hls_urls.fhd)"
                                        size="sm"
                                        class="h-8"
                                    >
                                        Copy
                                    </Button>
                                </div>

                                <!-- HD -->
                                <div v-if="show.hls_urls.hd" class="flex gap-2 items-center">
                                    <label class="text-primary-300 text-sm font-semibold w-24">720p HD:</label>
                                    <Input
                                        :modelValue="show.hls_urls.hd"
                                        readonly
                                        class="flex-1 font-mono text-xs h-8"
                                        @click="$event.target.select()"
                                    />
                                    <Button
                                        @click="copyToClipboard(show.hls_urls.hd)"
                                        size="sm"
                                        class="h-8"
                                    >
                                        Copy
                                    </Button>
                                </div>

                                <!-- SD -->
                                <div v-if="show.hls_urls.sd" class="flex gap-2 items-center">
                                    <label class="text-primary-300 text-sm font-semibold w-24">480p SD:</label>
                                    <Input
                                        :modelValue="show.hls_urls.sd"
                                        readonly
                                        class="flex-1 font-mono text-xs h-8"
                                        @click="$event.target.select()"
                                    />
                                    <Button
                                        @click="copyToClipboard(show.hls_urls.sd)"
                                        size="sm"
                                        class="h-8"
                                    >
                                        Copy
                                    </Button>
                                </div>

                                <!-- LD -->
                                <div v-if="show.hls_urls.ld" class="flex gap-2 items-center">
                                    <label class="text-primary-300 text-sm font-semibold w-24">320p LD:</label>
                                    <Input
                                        :modelValue="show.hls_urls.ld"
                                        readonly
                                        class="flex-1 font-mono text-xs h-8"
                                        @click="$event.target.select()"
                                    />
                                    <Button
                                        @click="copyToClipboard(show.hls_urls.ld)"
                                        size="sm"
                                        class="h-8"
                                    >
                                        Copy
                                    </Button>
                                </div>
                            </div>
                        </details>
                    </div>
                    <div v-else class="text-center py-8">
                        <svg class="w-16 h-16 text-primary-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h3 class="text-xl font-semibold text-primary-300 mb-2">Stream Not Available</h3>
                        <p class="text-primary-400">This show is not currently available for external viewing.</p>
                        <Link :href="route('shows.grid')" class="inline-block mt-4 px-4 py-2 bg-primary-700 hover:bg-primary-600 rounded transition-colors">
                            Browse Other Shows
                        </Link>
                    </div>
                </div>

                <!-- Instructions -->
                <div class="bg-primary-800 text-primary-100 overflow-hidden shadow-sm lg:rounded p-6">
                    <h2 class="text-xl font-semibold mb-4">How to Use These URLs</h2>

                    <div class="space-y-6">
                        <div class="flex items-start">
                            <span class="flex-shrink-0 w-8 h-8 bg-primary-700 rounded-full flex items-center justify-center text-sm font-bold mr-3">1</span>
                            <div>
                                <h3 class="font-semibold mb-1">Open Your Media Player</h3>
                                <p class="text-primary-300 text-sm">Launch VLC, MPV, or any HLS-compatible media player on your device.</p>
                            </div>
                        </div>

                        <div class="flex items-start">
                            <span class="flex-shrink-0 w-8 h-8 bg-primary-700 rounded-full flex items-center justify-center text-sm font-bold mr-3">2</span>
                            <div>
                                <h3 class="font-semibold mb-1">Open Network Stream</h3>
                                <p class="text-primary-300 text-sm">In VLC: Go to Media → Open Network Stream (Ctrl+N)<br>
                                In MPV: Just paste the URL as a command line argument</p>
                            </div>
                        </div>

                        <div class="flex items-start">
                            <span class="flex-shrink-0 w-8 h-8 bg-primary-700 rounded-full flex items-center justify-center text-sm font-bold mr-3">3</span>
                            <div>
                                <h3 class="font-semibold mb-1">Paste the Stream URL</h3>
                                <p class="text-primary-300 text-sm">Copy and paste the Master Playlist URL from above. This will give you adaptive quality streaming.</p>
                            </div>
                        </div>

                        <div class="flex items-start">
                            <span class="flex-shrink-0 w-8 h-8 bg-primary-700 rounded-full flex items-center justify-center text-sm font-bold mr-3">4</span>
                            <div>
                                <h3 class="font-semibold mb-1">Enjoy the Stream!</h3>
                                <p class="text-primary-300 text-sm">Click Play and enjoy watching "{{ show.title }}" on your preferred device.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 p-4 bg-primary-900 rounded">
                        <h4 class="font-semibold text-sm mb-2">💡 Pro Tips:</h4>
                        <ul class="text-sm text-primary-300 space-y-1">
                            <li>• Use the Master Playlist for the best experience - it automatically adjusts quality</li>
                            <li>• If you have a slow connection, choose a lower quality stream (SD or LD)</li>
                            <li>• You can save the URL as a playlist file in VLC for quick access</li>
                            <li>• These URLs work on Smart TVs with compatible media player apps</li>
                        </ul>
                    </div>
                </div>
        </Container>
    </authenticated-layout>
</template>

<style>
details summary::-webkit-details-marker {
    display: none;
}

details[open] summary::after {
    transform: rotate(90deg);
}

details summary::after {
    content: '▶';
    display: inline-block;
    margin-left: 0.5rem;
    transition: transform 0.2s;
}
</style>
