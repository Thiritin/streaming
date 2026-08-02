<template>
    <transition name="fade">
        <div v-if="visible" class="stats-overlay">
            <div class="stats-header">
                <h3>Stream Statistics</h3>
                <button @click="$emit('close')" class="close-btn">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="stats-content">
                <div class="stat-group">
                    <h4>Video</h4>
                    <div class="stat-item">
                        <span class="stat-label">Resolution:</span>
                        <span class="stat-value">{{ stats.resolution || stats.playlistResolution || 'N/A' }}</span>
                    </div>
                    <div class="stat-item" v-if="stats.fps">
                        <span class="stat-label">Framerate:</span>
                        <span class="stat-value">{{ stats.fps }} fps</span>
                    </div>
                    <div class="stat-item" v-if="stats.segmentBitrate">
                        <span class="stat-label">Stream Bitrate:</span>
                        <span class="stat-value">{{ formatBitrate(stats.segmentBitrate) }}</span>
                    </div>
                    <div class="stat-item" v-if="stats.videoCodec">
                        <span class="stat-label">Video Codec:</span>
                        <span class="stat-value">{{ stats.videoCodec }}</span>
                    </div>
                    <div class="stat-item" v-if="stats.audioCodec">
                        <span class="stat-label">Audio Codec:</span>
                        <span class="stat-value">{{ stats.audioCodec }}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Quality:</span>
                        <span class="stat-value">{{ stats.currentQuality ? `${stats.currentQuality} / ${stats.availableQualities}` : 'Auto' }}</span>
                    </div>
                </div>

                <div class="stat-group">
                    <h4>Network</h4>
                    <div class="stat-item">
                        <span class="stat-label">Download Speed:</span>
                        <span class="stat-value">{{ formatBitrate(stats.bandwidth) }}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Buffer:</span>
                        <span class="stat-value">{{ formatTime(stats.bufferLength) }}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Dropped Frames:</span>
                        <span class="stat-value">{{ stats.droppedFrames || 0 }}</span>
                    </div>
                </div>

                <div class="stat-group">
                    <h4>Live Stream</h4>
                    <div class="stat-item">
                        <span class="stat-label">Latency:</span>
                        <span class="stat-value" :class="getLatencyClass(stats.latency)">
                            {{ formatLatency(stats.latency) }}
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Volume:</span>
                        <span class="stat-value">{{ Math.round((stats.volume || 0) * 100) }}%</span>
                    </div>
                    <div class="stat-item" v-if="stats.mediaRequests">
                        <span class="stat-label">Segments Loaded:</span>
                        <span class="stat-value">{{ stats.mediaRequests }}</span>
                    </div>
                    <div class="stat-item" v-if="stats.mediaBytesTransferred">
                        <span class="stat-label">Data Transferred:</span>
                        <span class="stat-value">{{ formatBytes(stats.mediaBytesTransferred) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </transition>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    visible: {
        type: Boolean,
        default: false
    },
    player: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['close']);

const stats = ref({
    resolution: null,
    playlistResolution: null,
    fps: null,
    segmentBitrate: null,
    videoCodec: null,
    audioCodec: null,
    bandwidth: null,
    bufferLength: null,
    droppedFrames: null,
    latency: null,
    volume: 1,
    duration: 0,
    currentTime: 0,
    availableQualities: null,
    currentQuality: null,
    mediaRequests: 0,
    mediaBytesTransferred: 0
});

let statsInterval = null;

// hls.js has no cumulative transfer counters, so tally them from fragment loads.
// Tracked per-instance: a source change rebuilds the provider and resets these.
let countedHls = null;
let detachCounters = null;

const attachTransferCounters = (hls, ctor) => {
    if (countedHls === hls) return;

    detachCounters?.();

    const onFragLoaded = (_event, data) => {
        stats.value.mediaRequests += 1;
        stats.value.mediaBytesTransferred += data?.frag?.stats?.total ?? 0;
    };

    hls.on(ctor.Events.FRAG_LOADED, onFragLoaded);

    countedHls = hls;
    stats.value.mediaRequests = 0;
    stats.value.mediaBytesTransferred = 0;
    detachCounters = () => {
        hls.off(ctor.Events.FRAG_LOADED, onFragLoaded);
        countedHls = null;
        detachCounters = null;
    };
};

const formatBitrate = (bitrate) => {
    if (!bitrate || bitrate === 0 || bitrate === 1) return 'N/A';
    if (bitrate > 1000000) {
        return `${(bitrate / 1000000).toFixed(2)} Mbps`;
    } else if (bitrate > 1000) {
        return `${(bitrate / 1000).toFixed(0)} Kbps`;
    }
    return `${bitrate} bps`;
};

const formatTime = (seconds) => {
    if (!seconds || isNaN(seconds)) return '0:00';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    
    if (hours > 0) {
        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
};

const formatLatency = (latency) => {
    if (!latency) return 'N/A';
    if (latency < 1) {
        return `${Math.round(latency * 1000)}ms`;
    }
    return `${latency.toFixed(1)}s`;
};

const getLatencyClass = (latency) => {
    if (!latency) return '';
    if (latency < 3) return 'text-green-400';
    if (latency < 10) return 'text-yellow-400';
    return 'text-red-400';
};

const formatBytes = (bytes) => {
    if (!bytes || bytes === 0) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${sizes[i]}`;
};

const updateStats = () => {
    const player = props.player;
    if (!player) return;

    const state = player.state;
    if (!state) return;

    stats.value.volume = state.volume;
    stats.value.currentTime = state.currentTime;

    // Live streams have no meaningful duration; report distance from the edge instead.
    if (state.live) {
        stats.value.duration = null;
        stats.value.latency = Math.max(0, state.seekableEnd - state.currentTime);
    } else {
        stats.value.duration = state.duration;
        stats.value.latency = null;
    }

    stats.value.bufferLength = Math.max(0, state.bufferedEnd - state.currentTime);
    stats.value.availableQualities = state.qualities?.length || null;

    const provider = player.provider;
    const video = provider?.video;

    if (video) {
        if (video.videoWidth && video.videoHeight) {
            stats.value.resolution = `${video.videoWidth}x${video.videoHeight}`;
        }

        if (video.getVideoPlaybackQuality) {
            stats.value.droppedFrames = video.getVideoPlaybackQuality().droppedVideoFrames || 0;
        }
    }

    // Everything below is hls.js only. Safari playing HLS natively has no instance.
    const hls = provider?.instance;
    if (!hls) return;

    if (provider.ctor) attachTransferCounters(hls, provider.ctor);

    stats.value.bandwidth = hls.bandwidthEstimate;

    // hls.latency is only populated for low-latency streams; fall back to the
    // seekable-window figure already computed above.
    if (Number.isFinite(hls.latency) && hls.latency > 0) {
        stats.value.latency = hls.latency;
    }

    const level = hls.levels?.[hls.currentLevel];
    if (!level) return;

    stats.value.currentQuality = hls.currentLevel + 1;
    stats.value.segmentBitrate = level.bitrate;
    stats.value.videoCodec = level.videoCodec || null;
    stats.value.audioCodec = level.audioCodec || null;

    if (level.width && level.height) {
        stats.value.playlistResolution = `${level.width}x${level.height}`;
    }

    if (level.frameRate) {
        stats.value.fps = Math.round(level.frameRate);
    }
};

watch(() => props.visible, (newValue) => {
    if (newValue && props.player) {
        updateStats();
        statsInterval = setInterval(updateStats, 1000);
    } else if (statsInterval) {
        clearInterval(statsInterval);
        statsInterval = null;
    }
});

onMounted(() => {
    if (props.visible && props.player) {
        updateStats();
        statsInterval = setInterval(updateStats, 1000);
    }
});

onUnmounted(() => {
    if (statsInterval) {
        clearInterval(statsInterval);
    }
    detachCounters?.();
});
</script>

<style scoped>
.stats-overlay {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(0, 0, 0, 0.9);
    color: white;
    padding: 15px;
    border-radius: 8px;
    min-width: 300px;
    z-index: 100;
    font-size: 14px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.stats-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.stats-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.close-btn {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

.stats-content {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.stat-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.stat-group h4 {
    margin: 0 0 4px 0;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
}

.stat-label {
    color: rgba(255, 255, 255, 0.7);
    font-size: 13px;
}

.stat-value {
    font-weight: 500;
    font-size: 13px;
    font-family: 'Courier New', monospace;
}

.fade-enter-active, .fade-leave-active {
    transition: opacity 0.3s ease;
}

.fade-enter-from, .fade-leave-to {
    opacity: 0;
}

@media (max-width: 640px) {
    .stats-overlay {
        top: 50%;
        left: 50%;
        right: auto;
        transform: translate(-50%, -50%);
        width: 90%;
        max-width: 350px;
    }
}
</style>