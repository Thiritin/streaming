<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link } from "@inertiajs/vue3";
import Container from "@/Components/Container.vue";
import Input from "@/Components/ui/Input.vue";
import Button from "@/Components/ui/Button.vue";

const props = defineProps({
    show: {
        type: Object,
        required: true,
    },
    playlists: {
        type: Array,
        default: () => [],
    },
    personal: {
        type: Boolean,
        default: false,
    },
});

const copied = ref(null);
let copiedTimer = null;

const copy = async (entry) => {
    try {
        await navigator.clipboard.writeText(entry.url);
    } catch {
        return;
    }

    copied.value = entry.key;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied.value = null), 2000);
};

const statusTone = {
    live: 'text-green-400',
    scheduled: 'text-yellow-400',
    ended: 'text-red-400',
    cancelled: 'text-red-400',
};

const players = [
    { name: 'VLC', how: 'Media → Open Network Stream, paste, Play.' },
    { name: 'mpv / IINA', how: 'mpv "<url>", or drop the URL into IINA\'s Open URL box.' },
    { name: 'ffplay', how: 'ffplay "<url>"' },
    { name: 'Smart TV / set-top box', how: 'Any app that opens an HLS or m3u8 URL.' },
];
</script>

<template>
    <authenticated-layout>
        <Head>
            <title>{{ show.title }} - External Player</title>
        </Head>

        <div class="bg-primary-900 border-b border-primary-800 py-3">
            <Container>
                <div class="flex items-center text-sm">
                    <Link :href="route('show.view', show.slug)" class="inline-flex items-center text-primary-400 hover:text-white transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Back to Player
                    </Link>
                    <span class="mx-3 text-primary-600">|</span>
                    <Link :href="route('shows.grid')" class="text-primary-400 hover:text-white transition-colors">All Shows</Link>
                </div>

                <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h1 class="text-white font-semibold text-xl">{{ show.title }}</h1>
                    <span v-if="show.source" class="text-primary-400 text-sm">{{ show.source }}</span>
                    <span :class="statusTone[show.status] || 'text-primary-400'" class="text-sm font-medium uppercase">
                        ● {{ show.status }}
                    </span>
                </div>
                <p class="mt-1 text-primary-400 text-sm">
                    Open this stream in VLC, mpv, a Smart TV app, or anything else that plays HLS.
                </p>
            </Container>
        </div>

        <Container class="py-8">
            <div v-if="playlists.length" class="space-y-6">
                <div class="bg-primary-800 lg:rounded shadow-sm divide-y divide-primary-700">
                    <div
                        v-for="entry in playlists"
                        :key="entry.key"
                        class="p-4 sm:p-5"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-white font-semibold">{{ entry.label }}</h2>
                            <span class="text-xs text-primary-400">{{ entry.detail }}</span>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <Input
                                :modelValue="entry.url"
                                readonly
                                class="flex-1 font-mono text-xs"
                                @click="$event.target.select()"
                            />
                            <Button
                                @click="copy(entry)"
                                :variant="copied === entry.key ? 'secondary' : 'default'"
                                class="shrink-0 w-24"
                            >
                                {{ copied === entry.key ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <div v-if="personal" class="flex gap-3 rounded border border-yellow-600/40 bg-yellow-500/10 p-4">
                    <svg class="w-5 h-5 shrink-0 text-yellow-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <p class="text-sm text-yellow-100">
                        These URLs carry your personal stream key. Anyone you send one to is watching as you, so keep them to your own devices.
                    </p>
                </div>

                <div class="bg-primary-800 lg:rounded shadow-sm p-5">
                    <h2 class="text-white font-semibold mb-4">Where to paste it</h2>
                    <dl class="space-y-3">
                        <div v-for="player in players" :key="player.name" class="sm:flex sm:gap-4">
                            <dt class="text-primary-200 text-sm font-medium sm:w-48 sm:shrink-0">{{ player.name }}</dt>
                            <dd class="text-primary-400 text-sm">{{ player.how }}</dd>
                        </div>
                    </dl>

                    <p class="mt-5 text-xs text-primary-400">
                        Automatic quality is the right choice almost always: it starts low and climbs as the connection allows.
                        Pick a fixed rung only when a player handles switching badly, or when you want to cap what the stream pulls.
                    </p>
                </div>
            </div>

            <div v-else class="bg-primary-800 lg:rounded shadow-sm p-10 text-center">
                <svg class="w-14 h-14 text-primary-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h2 class="text-xl font-semibold text-primary-200 mb-2">Nothing to play yet</h2>
                <p class="text-primary-400">
                    External URLs appear once this show is live.
                </p>
                <Link :href="route('shows.grid')" class="inline-block mt-5 px-4 py-2 bg-primary-700 hover:bg-primary-600 text-white rounded transition-colors">
                    Browse other shows
                </Link>
            </div>
        </Container>
    </authenticated-layout>
</template>
