<template>
    <div>
        <Head :title="recording.title" />

        <!-- Video Player Container - Full width on mobile -->
        <div class="sm:px-4 lg:px-8 sm:pt-8">
            <div class="max-w-6xl mx-auto">
                <div class="relative bg-black sm:rounded-lg overflow-hidden sm:shadow-2xl">
                    <!-- Loading Spinner -->
                    <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-black/80 z-10">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-white"></div>
                    </div>

                    <!-- Error State -->
                    <div v-if="error && !loading" class="absolute inset-0 flex flex-col items-center justify-center bg-black/90 z-10">
                        <FaVideoSlashIcon class="w-16 h-16 text-red-500 mb-4" />
                        <p class="text-white text-lg mb-4">{{ errorMessage }}</p>
                        <button
                            @click="retryPlayback"
                            class="px-4 py-2 bg-primary-600 hover:bg-primary-500 text-white rounded-lg transition-colors"
                        >
                            Retry Playback
                        </button>
                    </div>

                    <!-- Landing half of the shared-element morph from the archive
                         tile. See composables/useMediaHero.js. -->
                    <VideoPlayer
                        :key="playerKey"
                        v-media-hero
                        :src="recording.m3u8_url"
                        :title="recording.title"
                        :poster="recording.thumbnail_url"
                        :is-live="false"
                        :autoplay="true"
                        @can-play="handleCanPlay"
                        @error="handleError"
                    />
                </div>
            </div>
        </div>

        <!-- Video Information - No card on mobile -->
        <div class="px-4 lg:px-8 py-6">
            <div class="max-w-6xl mx-auto">
                <div class="sm:bg-primary-800 sm:rounded-lg sm:shadow-lg sm:p-6 mb-6">
                    <h1 class="text-2xl font-bold text-white mb-4">
                        {{ recording.title }}
                    </h1>

                    <div class="flex flex-wrap items-center gap-4 text-sm mb-4">
                        <span class="flex items-center text-primary-400">
                            <FaCalendarIcon class="w-4 h-4 mr-1" />
                            {{ formatDate(recording.date) }}
                        </span>
                        <span v-if="recording.views" class="flex items-center text-primary-400">
                            <FaEyeIcon class="w-4 h-4 mr-1" />
                            {{ formatViews(recording.views) }} views
                        </span>
                        <span v-if="recording.duration" class="flex items-center text-primary-400">
                            <FaClockIcon class="w-4 h-4 mr-1" />
                            {{ formatDuration(recording.duration) }}
                        </span>
                    </div>

                    <p v-if="recording.description" class="text-primary-300 whitespace-pre-wrap leading-relaxed">
                        {{ recording.description }}
                    </p>
                </div>

                <!-- Navigation -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <Link
                        :href="route('recordings.index')"
                        class="inline-flex items-center text-primary-400 hover:text-primary-200 transition-colors"
                    >
                        <FaArrowLeftIcon class="w-5 h-5 mr-2" />
                        Back to Archive
                    </Link>

                    <!-- Hosting Sponsor -->
                    <a
                        href="https://pawhost.de"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex items-center gap-4 px-6 py-3 bg-primary-800/50 hover:bg-primary-800 border border-primary-700/50 rounded-xl transition-all group"
                    >
                        <span class="text-sm text-primary-400 uppercase tracking-wide font-medium">Hosting sponsored by</span>
                        <img
                            :src="pawHostLogo"
                            alt="PawHost"
                            class="h-10 opacity-80 group-hover:opacity-100 transition-opacity"
                        />
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VideoPlayer from '@/Components/Player/VideoPlayer.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';
import FaEyeIcon from '@/Components/Icons/FaEyeIcon.vue';
import FaCalendarIcon from '@/Components/Icons/FaCalendarIcon.vue';
import FaClockIcon from '@/Components/Icons/FaClockIcon.vue';
import FaArrowLeftIcon from '@/Components/Icons/FaArrowLeftIcon.vue';
import pawHostLogo from '../../images/pawhost_white.svg';

defineOptions({
    layout: AuthenticatedLayout
});

const props = defineProps({
    recording: {
        type: Object,
        required: true
    }
});

const loading = ref(true);
const error = ref(false);
const errorMessage = ref('');

// Bumping this remounts VideoPlayer, which is the cleanest way to rebuild the
// provider and start the load from scratch.
const playerKey = ref(0);

const handleCanPlay = () => {
    loading.value = false;
    error.value = false;
};

const handleError = (detail) => {
    console.error('Recording playback error:', detail);
    loading.value = false;
    error.value = true;
    errorMessage.value = detail?.message || 'This recording could not be played.';
};

const retryPlayback = () => {
    error.value = false;
    loading.value = true;
    playerKey.value += 1;
};

const formatDuration = (seconds) => {
    if (!seconds) return '';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${minutes}:${String(secs).padStart(2, '0')}`;
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatViews = (views) => {
    if (views < 1000) {
        return views.toString();
    } else if (views < 1000000) {
        return (views / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    } else {
        return (views / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
};
</script>
