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
                    <div class="stat-item" v-if="stats.throughput">
                        <span class="stat-label">Processing Speed:</span>
                        <span class="stat-value">{{ formatBitrate(stats.throughput) }}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">System Bandwidth:</span>
                        <span class="stat-value">{{ formatBitrate(stats.bitrate) }}</span>
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
    bitrate: null,
    segmentBitrate: null,
    videoCodec: null,
    audioCodec: null,
    bandwidth: null,
    throughput: null,
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
    if (!props.player) return;

    const player = props.player;
    
    // Get video dimensions
    if (player.videoHeight && player.videoWidth) {
        stats.value.resolution = `${player.videoWidth()}x${player.videoHeight()}`;
    }

    // Get playback stats (only show relevant ones for live streams)
    stats.value.volume = player.volume();
    
    // For live streams, show live edge status instead of duration/position
    if (player.liveTracker) {
        const liveCurrentTime = player.liveTracker.liveCurrentTime();
        const currentTime = player.currentTime();
        stats.value.latency = Math.max(0, liveCurrentTime - currentTime);
        stats.value.currentTime = currentTime;
        // Don't show duration for live streams
        stats.value.duration = null;
    } else {
        stats.value.duration = player.duration();
        stats.value.currentTime = player.currentTime();
    }

    // Get buffer info
    const buffered = player.buffered();
    if (buffered.length > 0) {
        const currentTime = player.currentTime();
        let bufferEnd = 0;
        for (let i = 0; i < buffered.length; i++) {
            if (buffered.start(i) <= currentTime && buffered.end(i) > currentTime) {
                bufferEnd = buffered.end(i);
                break;
            }
        }
        stats.value.bufferLength = bufferEnd - currentTime;
    }

    // Get tech-specific stats
    const tech = player.tech({ IWillNotUseThisInPlugins: true });
    if (tech && tech.el_) {
        const videoEl = tech.el_;
        
        // Get dropped frames
        if (videoEl.getVideoPlaybackQuality) {
            const quality = videoEl.getVideoPlaybackQuality();
            stats.value.droppedFrames = quality.droppedVideoFrames || 0;
        }
    }

    // Get VHS (HLS) specific stats
    if (tech && tech.vhs) {
        const vhs = tech.vhs;
        
        // Get bandwidth metrics
        if (vhs.bandwidth) {
            stats.value.bandwidth = vhs.bandwidth; // Network bandwidth
        }
        
        if (vhs.systemBandwidth) {
            stats.value.bitrate = vhs.systemBandwidth; // Overall system bandwidth
        }
        
        if (vhs.throughput) {
            // Throughput is the processing speed after download
            stats.value.throughput = vhs.throughput;
        }

        // Get current media playlist info
        if (vhs.playlists) {
            const media = vhs.playlists.media();
            if (media && media.attributes) {
                // Get actual segment bitrate
                if (media.attributes.BANDWIDTH) {
                    stats.value.segmentBitrate = media.attributes.BANDWIDTH;
                }
                // Get codec info
                if (media.attributes.CODECS) {
                    const codecs = media.attributes.CODECS.split(',');
                    stats.value.videoCodec = codecs[0] || 'N/A';
                    if (codecs[1]) {
                        stats.value.audioCodec = codecs[1];
                    }
                }
                // Get resolution from playlist
                if (media.attributes.RESOLUTION) {
                    stats.value.playlistResolution = `${media.attributes.RESOLUTION.width}x${media.attributes.RESOLUTION.height}`;
                }
                // Frame rate if available
                if (media.attributes['FRAME-RATE']) {
                    stats.value.fps = Math.round(media.attributes['FRAME-RATE']);
                }
            }
            
            // Get main playlist info
            const main = vhs.playlists.main;
            if (main && main.playlists) {
                stats.value.availableQualities = main.playlists.length;
                
                // Find current quality index
                const currentMedia = vhs.playlists.media();
                if (currentMedia) {
                    const index = main.playlists.findIndex(p => p === currentMedia || p.id === currentMedia.id);
                    if (index !== -1) {
                        stats.value.currentQuality = index + 1;
                    }
                }
            }
        }

        // Get segment loading stats
        if (vhs.stats) {
            stats.value.mediaRequests = vhs.stats.mediaRequests || 0;
            stats.value.mediaBytesTransferred = vhs.stats.mediaBytesTransferred || 0;
        }
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