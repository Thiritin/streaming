<script setup>
import VideoJsPlayer from "@/Components/Livestream/VideoJsPlayer.vue";
import { ref, computed } from 'vue';

const props = defineProps({
    hlsUrls: Object,
    showInfo: Object
})

// Handle player events
const handleError = (error) => {
    console.error('Player error:', error);
};

const handlePlaying = () => {
    console.log('Stream is playing');
};

const handleQualityChanged = (quality) => {
    console.log('Quality changed:', quality);
    if (props.showInfo) {
        console.log('Playing show:', props.showInfo.title);
    }
};

const playerRef = ref(null);

// Expose methods for parent component
defineExpose({
    play: () => playerRef.value?.play(),
    pause: () => playerRef.value?.pause(),
    seekToLive: () => playerRef.value?.seekToLive(),
});
</script>

<template>
    <div class="stream-player-container" v-if="hlsUrls && hlsUrls.master">
        <VideoJsPlayer 
            :stream-url="hlsUrls.master"
            :hls-urls="hlsUrls"
            :autoplay="true"
            :muted="false"
            :controls="true"
            :is-live="true"
            @error="handleError"
            @playing="handlePlaying"
            @qualityChanged="handleQualityChanged"
            ref="playerRef"
        />
        
        <!-- Show info overlay (optional) -->
        <div v-if="showInfo" class="show-info-overlay">
            <div class="show-title">{{ showInfo.title }}</div>
            <div v-if="showInfo.source" class="show-source">{{ showInfo.source }}</div>
        </div>
    </div>
    <div v-else class="no-stream-message">
        <p>No stream available at the moment.</p>
    </div>
</template>

<style scoped>
.stream-player-container {
    width: 100%;
    height: 100%;
    position: relative;
}

.show-info-overlay {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 8px 12px;
    border-radius: 4px;
    z-index: 10;
    pointer-events: none;
}

.show-title {
    font-weight: bold;
    font-size: 14px;
}

.show-source {
    font-size: 12px;
    opacity: 0.8;
}

.no-stream-message {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #999;
    font-size: 18px;
}

:deep(.video-js-container) {
    width: 100%;
    height: 100%;
}
</style>