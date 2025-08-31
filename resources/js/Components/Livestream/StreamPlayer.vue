<script setup>
import VideoJsPlayer from "@/Components/Livestream/VideoJsPlayer.vue";
import StreamStatsOverlay from "@/Components/Livestream/StreamStatsOverlay.vue";
import { ref, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    hlsUrl: String,
    showInfo: Object
})

// State for controls visibility
const controlsVisible = ref(true);
const showUnmutePrompt = ref(false);
const showStats = ref(false);

// Detect if user navigated internally vs direct page load
// Internal navigation means user has already interacted with the site
const isInternalNavigation = ref(false);

onMounted(() => {
    // Check if this is an internal navigation
    // We can detect this by checking if there's a referrer from the same origin
    // or by checking session storage for a navigation flag
    const referrer = document.referrer;
    const currentOrigin = window.location.origin;
    
    // Check if referrer is from same origin
    if (referrer && referrer.startsWith(currentOrigin)) {
        isInternalNavigation.value = true;
        console.log('Internal navigation detected - will try unmuted autoplay');
    } else {
        // Check session storage as backup
        const hasNavigated = sessionStorage.getItem('has_navigated');
        if (hasNavigated === 'true') {
            isInternalNavigation.value = true;
            console.log('Previous navigation detected - will try unmuted autoplay');
        } else {
            console.log('Direct page load - will use muted autoplay');
        }
    }
    
    // Mark that user has navigated within the site
    sessionStorage.setItem('has_navigated', 'true');
});

// Handle player events
const handleError = (error) => {
    console.error('Player error:', error);
};

const handlePlaying = () => {
    console.log('Stream is playing');
    // Check if we should show unmute prompt
    if (playerRef.value?.getPlayer && playerRef.value.getPlayer().muted()) {
        showUnmutePrompt.value = true;
        // Hide prompt after 5 seconds or when player is unmuted
        setTimeout(() => {
            if (playerRef.value?.getPlayer && playerRef.value.getPlayer().muted()) {
                showUnmutePrompt.value = false;
            }
        }, 5000);
    }
};

// Watch for volume changes to hide prompt when unmuted
const handleVolumeChange = () => {
    if (playerRef.value?.getPlayer && !playerRef.value.getPlayer().muted()) {
        showUnmutePrompt.value = false;
    }
};

const handleUnmute = () => {
    if (playerRef.value?.getPlayer) {
        const player = playerRef.value.getPlayer();
        try {
            player.muted(false);
            showUnmutePrompt.value = false;
        } catch (error) {
            console.log('Could not unmute:', error);
            // If unmuting fails, at least hide the prompt since user tried
            showUnmutePrompt.value = false;
        }
    }
};

const handleQualityChanged = (quality) => {
    console.log('Quality changed:', quality);
    if (props.showInfo) {
        console.log('Playing show:', props.showInfo.title);
    }
};

const handleUserActive = () => {
    controlsVisible.value = true;
};

const handleUserInactive = () => {
    controlsVisible.value = false;
};

const handleToggleStats = () => {
    showStats.value = !showStats.value;
};

const playerRef = ref(null);

// Expose methods for parent component
defineExpose({
    play: () => playerRef.value?.play(),
    pause: () => playerRef.value?.pause(),
    seekToLive: () => playerRef.value?.seekToLive(),
    handleToggleStats
});
</script>

<template>
    <div class="stream-player-container" v-if="hlsUrl">
        <VideoJsPlayer 
            :stream-url="hlsUrl"
            :hls-url="hlsUrl"
            :autoplay="true"
            :muted="!isInternalNavigation"
            :controls="true"
            :is-live="true"
            :is-internal-navigation="isInternalNavigation"
            @error="handleError"
            @playing="handlePlaying"
            @qualityChanged="handleQualityChanged"
            @useractive="handleUserActive"
            @userinactive="handleUserInactive"
            @volumechange="handleVolumeChange"
            @toggleStats="handleToggleStats"
            ref="playerRef"
        />
        
        
        <!-- Stream Statistics Overlay -->
        <StreamStatsOverlay 
            :visible="showStats"
            :player="playerRef?.getPlayer && playerRef.getPlayer()"
            @close="showStats = false"
        />
        
        <!-- Unmute prompt -->
        <transition name="fade">
            <div v-if="showUnmutePrompt" class="unmute-prompt" @click="handleUnmute">
                <svg class="unmute-icon" viewBox="0 0 24 24" width="24" height="24">
                    <path fill="currentColor" d="M3,9H7L12,4V20L7,15H3V9M16.59,12L14,9.41L15.41,8L18,10.59L20.59,8L22,9.41L19.41,12L22,14.59L20.59,16L18,13.41L15.41,16L14,14.59L16.59,12Z"/>
                </svg>
                <span>Click to unmute</span>
            </div>
        </transition>
    </div>
    <div v-else class="no-stream-message">
        <p>No stream available at the moment.</p>
    </div>
</template>

<style>
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

/* Fade transition for overlay */
.fade-enter-active, .fade-leave-active {
    transition: opacity 0.8s ease;
}

.fade-enter-from, .fade-leave-to {
    opacity: 0;
}

/* Unmute prompt styling */
.unmute-prompt {
    position: absolute;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 12px 20px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    z-index: 15;
    transition: background 0.2s;
}

.unmute-prompt:hover {
    background: rgba(0, 0, 0, 0.9);
}

.unmute-icon {
    width: 24px;
    height: 24px;
}
</style>