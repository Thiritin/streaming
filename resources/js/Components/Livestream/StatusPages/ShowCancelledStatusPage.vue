<template>
    <div class="relative w-full aspect-video bg-black flex items-center justify-center text-white">
        <div class="text-center p-8 max-w-2xl">
            <div class="mb-6">
                <svg class="w-24 h-24 mx-auto text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            
            <h2 class="text-3xl font-bold mb-4">{{ show.title }}</h2>
            <p class="text-xl mb-6 text-red-400">This Show Has Been Cancelled</p>
            
            <p class="text-primary-400 mb-8">
                We apologize for any inconvenience. This show will not be broadcast.
            </p>
            
            <!-- Other Live Shows -->
            <div v-if="otherLiveShows && otherLiveShows.length > 0" class="mt-8 border-t border-primary-700 pt-8">
                <h3 class="text-lg font-semibold mb-4">Currently Live</h3>
                <div class="space-y-3">
                    <a 
                        v-for="liveShow in otherLiveShows" 
                        :key="liveShow.id"
                        :href="`/shows/${liveShow.slug}`"
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
            
            <!-- Upcoming Shows -->
            <div v-else-if="upcomingShows && upcomingShows.length > 0" class="mt-8 border-t border-primary-700 pt-8">
                <h3 class="text-lg font-semibold mb-4">Upcoming Shows</h3>
                <div class="space-y-3">
                    <a 
                        v-for="upcomingShow in upcomingShows" 
                        :key="upcomingShow.id"
                        :href="`/shows/${upcomingShow.slug}`"
                        class="block bg-primary-800 hover:bg-primary-700 rounded-lg p-4 transition-colors"
                    >
                        <div class="text-left">
                            <h4 class="font-semibold">{{ upcomingShow.title }}</h4>
                            <p class="text-sm text-primary-400">
                                {{ formatScheduledTime(upcomingShow.scheduled_start) }}
                            </p>
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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    Back to Schedule
                </a>
            </div>
        </div>
    </div>
</template>

<script setup>
import dayjs from 'dayjs';

const props = defineProps({
    show: {
        type: Object,
        required: true
    },
    otherLiveShows: {
        type: Array,
        default: () => []
    },
    upcomingShows: {
        type: Array,
        default: () => []
    },
    mainStreamUrl: {
        type: String,
        default: '/schedule'
    }
});

const formatScheduledTime = (dateString) => {
    if (!dateString) return '';
    return dayjs(dateString).format('MMM D [at] h:mm A');
};
</script>