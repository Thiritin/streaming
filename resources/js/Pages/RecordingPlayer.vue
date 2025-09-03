<template>
    <div>
        <Head :title="recording.title" />
        
        <Container class="py-8">
            <div class="max-w-6xl mx-auto">
                <!-- Video Player Container -->
                <div class="relative bg-black rounded-lg overflow-hidden mb-6 shadow-2xl">
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
                    
                    <!-- Video Element -->
                    <video 
                        ref="videoPlayer"
                        class="w-full aspect-video"
                        controls
                        :poster="recording.thumbnail_url"
                        @loadstart="handleLoadStart"
                        @canplay="handleCanPlay"
                        @error="handleVideoError"
                    ></video>
                </div>

                <!-- Video Information -->
                <div class="bg-primary-800 rounded-lg shadow-lg p-6 mb-6">
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
                <div class="flex justify-between items-center">
                    <Link 
                        :href="route('recordings.index')" 
                        class="inline-flex items-center text-primary-400 hover:text-primary-200 transition-colors"
                    >
                        <FaArrowLeftIcon class="w-5 h-5 mr-2" />
                        Back to Recordings
                    </Link>
                </div>
            </div>
        </Container>
    </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Container from '@/Components/Container.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';
import FaEyeIcon from '@/Components/Icons/FaEyeIcon.vue';
import FaCalendarIcon from '@/Components/Icons/FaCalendarIcon.vue';
import FaClockIcon from '@/Components/Icons/FaClockIcon.vue';
import FaArrowLeftIcon from '@/Components/Icons/FaArrowLeftIcon.vue';
import Hls from 'hls.js';

// Define layout
defineOptions({
    layout: AuthenticatedLayout
});

const props = defineProps({
    recording: {
        type: Object,
        required: true
    }
});

const videoPlayer = ref(null);
const loading = ref(true);
const error = ref(false);
const errorMessage = ref('');
let hlsInstance = null;

const handleLoadStart = () => {
    loading.value = true;
    error.value = false;
};

const handleCanPlay = () => {
    loading.value = false;
    error.value = false;
};

const handleVideoError = (e) => {
    loading.value = false;
    error.value = true;
    errorMessage.value = 'Failed to load video. Please try again.';
    console.error('Video error:', e);
};

const initializePlayer = () => {
    if (videoPlayer.value) {
        if (Hls.isSupported()) {
            hlsInstance = new Hls({
                debug: false,
                enableWorker: true,
                lowLatencyMode: false,
                maxBufferLength: 30,
                maxMaxBufferLength: 600,
            });
            
            hlsInstance.loadSource(props.recording.m3u8_url);
            hlsInstance.attachMedia(videoPlayer.value);
            
            hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
                videoPlayer.value.play().catch(e => {
                    console.log('Autoplay was prevented:', e);
                });
            });
            
            hlsInstance.on(Hls.Events.ERROR, (event, data) => {
                if (data.fatal) {
                    error.value = true;
                    loading.value = false;
                    console.error('HLS fatal error:', data);
                    
                    switch(data.type) {
                        case Hls.ErrorTypes.NETWORK_ERROR:
                            errorMessage.value = 'Network error. Please check your connection.';
                            setTimeout(() => {
                                hlsInstance.startLoad();
                            }, 3000);
                            break;
                        case Hls.ErrorTypes.MEDIA_ERROR:
                            errorMessage.value = 'Media error. Attempting recovery...';
                            hlsInstance.recoverMediaError();
                            break;
                        default:
                            errorMessage.value = 'Unable to play this video.';
                            hlsInstance.destroy();
                            break;
                    }
                }
            });
        } else if (videoPlayer.value.canPlayType('application/vnd.apple.mpegurl')) {
            // Native HLS support (Safari)
            videoPlayer.value.src = props.recording.m3u8_url;
            videoPlayer.value.addEventListener('loadedmetadata', () => {
                videoPlayer.value.play().catch(e => {
                    console.log('Autoplay was prevented:', e);
                });
            });
        }
    }
};

const retryPlayback = () => {
    error.value = false;
    loading.value = true;
    if (hlsInstance) {
        hlsInstance.destroy();
    }
    initializePlayer();
};

onMounted(() => {
    initializePlayer();
});

onUnmounted(() => {
    if (hlsInstance) {
        hlsInstance.destroy();
    }
});

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