<template>
    <div class="relative w-full aspect-video bg-black flex items-center justify-center text-white">
        <div class="text-center p-8 max-w-2xl">
            <div class="mb-6">
                <svg class="w-24 h-24 mx-auto text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            
            <h2 class="text-3xl font-bold mb-4">{{ show.title }}</h2>
            <p class="text-xl mb-6 text-primary-300">This Stream Has Ended</p>
            
            <div v-if="show.actual_start && show.actual_end" class="mb-6 text-primary-400">
                <p>Duration: {{ streamDuration }}</p>
                <p v-if="show.peak_viewer_count">Peak Viewers: {{ show.peak_viewer_count }}</p>
            </div>
            
            <p class="text-primary-400 mb-8">
                Thank you for watching!
            </p>
            
            <!-- Other Live Shows -->
            <div v-if="otherLiveShows && otherLiveShows.length > 0" class="mt-8 border-t border-primary-700 pt-8">
                <h3 class="text-lg font-semibold mb-4">Other Live Shows</h3>
                <div class="space-y-3">
                    <a 
                        v-for="liveShow in otherLiveShows" 
                        :key="liveShow.id"
                        :href="route('show.view', liveShow.slug)"
                        class="block bg-primary-800 hover:bg-primary-700 rounded-lg p-4 transition-colors"
                    >
                        <div class="flex items-center justify-between">
                            <div class="text-left">
                                <h4 class="font-semibold">{{ liveShow.title }}</h4>
                                <p class="text-sm text-primary-400">{{ liveShow.source?.name }}</p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="flex h-2 w-2">
                                    <span class="animate-ping absolute inline-flex h-2 w-2 rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                                </span>
                                <span class="text-sm text-red-400">LIVE</span>
                            </div>
                        </div>
                        <div v-if="liveShow.viewer_count > 0" class="mt-2 text-sm text-primary-400">
                            {{ liveShow.viewer_count }} {{ liveShow.viewer_count === 1 ? 'viewer' : 'viewers' }}
                        </div>
                    </a>
                </div>
            </div>
            
            <!-- Main Stream Link -->
            <div v-else-if="mainStreamUrl" class="mt-8">
                <a 
                    :href="mainStreamUrl"
                    class="inline-flex items-center px-6 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Watch Main Stream
                </a>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import dayjs from 'dayjs';
import duration from 'dayjs/plugin/duration';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(duration);
dayjs.extend(relativeTime);

const props = defineProps({
    show: {
        type: Object,
        required: true
    },
    otherLiveShows: {
        type: Array,
        default: () => []
    },
    mainStreamUrl: {
        type: String,
        default: '/stream'
    }
});

const streamDuration = computed(() => {
    if (!props.show?.actual_start || !props.show?.actual_end) return '';
    
    const start = dayjs(props.show.actual_start);
    const end = dayjs(props.show.actual_end);
    const diff = end.diff(start);
    
    return dayjs.duration(diff).humanize();
});
</script>