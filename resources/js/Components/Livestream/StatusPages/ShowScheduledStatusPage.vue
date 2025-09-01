<template>
    <div class="relative w-full aspect-video bg-black flex items-center justify-center text-white">
        <div class="text-center p-8 max-w-2xl">
            <div class="mb-6">
                <svg class="w-24 h-24 mx-auto text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            
            <h2 class="text-3xl font-bold mb-4">{{ show.title }}</h2>
            <p class="text-xl mb-2">Scheduled to Start</p>
            
            <div v-if="scheduledStart" class="text-2xl font-mono mb-6">
                {{ formatScheduledTime }}
            </div>
            
            <div v-if="timeUntilStart" class="mb-6">
                <p class="text-lg text-primary-300">Starting in</p>
                <p class="text-3xl font-bold text-primary-400">{{ timeUntilStart }}</p>
            </div>
            
            <p class="text-primary-400 mb-4">
                Please wait, the stream will start automatically when the show goes live.
            </p>
            
            <div class="animate-pulse flex justify-center space-x-2">
                <div class="w-2 h-2 bg-primary-400 rounded-full"></div>
                <div class="w-2 h-2 bg-primary-400 rounded-full animation-delay-200"></div>
                <div class="w-2 h-2 bg-primary-400 rounded-full animation-delay-400"></div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import dayjs from 'dayjs';

const props = defineProps({
    show: {
        type: Object,
        required: true
    }
});

const now = ref(new Date());
let intervalId = null;

const scheduledStart = computed(() => {
    return props.show?.scheduled_start ? dayjs(props.show.scheduled_start) : null;
});

const formatScheduledTime = computed(() => {
    if (!scheduledStart.value) return '';
    return scheduledStart.value.format('MMMM D, YYYY [at] h:mm A');
});

const timeUntilStart = computed(() => {
    if (!scheduledStart.value) return null;
    
    const diff = scheduledStart.value.diff(dayjs(now.value), 'seconds');
    if (diff <= 0) return 'Any moment now...';
    
    const hours = Math.floor(diff / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;
    
    if (hours > 0) {
        return `${hours}h ${minutes}m ${seconds}s`;
    } else if (minutes > 0) {
        return `${minutes}m ${seconds}s`;
    } else {
        return `${seconds}s`;
    }
});

onMounted(() => {
    // Update the time every second
    intervalId = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (intervalId) {
        clearInterval(intervalId);
    }
});
</script>

<style scoped>
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.animation-delay-200 {
    animation-delay: 200ms;
}

.animation-delay-400 {
    animation-delay: 400ms;
}
</style>