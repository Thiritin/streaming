<template>
    <div class="video-js-container">
        <video
            ref="videoPlayer"
            class="video-js vjs-default-skin vjs-big-play-centered vjs-fluid"
            :id="playerId"
        ></video>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import videojs from 'video.js';
import 'video.js/dist/video-js.css';
import qualityLevels from 'videojs-contrib-quality-levels';
import hlsQualitySelector from 'videojs-hls-quality-selector';

// Register plugins
videojs.registerPlugin('qualityLevels', qualityLevels);
videojs.registerPlugin('hlsQualitySelector', hlsQualitySelector);

const props = defineProps({
    streamUrl: {
        type: String,
        required: true
    },
    hlsUrls: {
        type: Object,
        default: null
    },
    autoplay: {
        type: Boolean,
        default: true
    },
    muted: {
        type: Boolean,
        default: false
    },
    controls: {
        type: Boolean,
        default: true
    },
    isLive: {
        type: Boolean,
        default: true
    },
    playerId: {
        type: String,
        default: 'video-player'
    }
});

const emit = defineEmits(['error', 'playing', 'pause', 'ended', 'loadedmetadata', 'qualityChanged']);

const videoPlayer = ref(null);
let player = null;

const initializePlayer = () => {
    if (!videoPlayer.value) return;
    
    // Video.js options
    const options = {
        autoplay: props.autoplay ? 'muted' : false, // Use 'muted' for autoplay to work in modern browsers
        controls: props.controls,
        muted: props.muted,
        fluid: true,
        responsive: true,
        preload: 'auto',
        liveui: props.isLive,
        liveTracker: {
            trackingThreshold: 0,
            liveTolerance: 15
        },
        html5: {
            vhs: {
                overrideNative: true,
                smoothQualityChange: true,
                fastQualityChange: true,
                handlePartialData: true,
                useBandwidthFromLocalStorage: true,
                limitRenditionByPlayerDimensions: true,
                useDevicePixelRatio: true,
                bandwidth: 16777216, // Start with 16 Mbps bandwidth assumption
                enableLowInitialPlaylist: true,
                smoothQualityChange: true,
                allowSeeksWithinUnsafeLiveWindow: true,
                handlePartialData: true,
                customTagParsers: [],
                customTagMappers: [],
                experimentalBufferBasedABR: false,
                experimentalLLHLS: false,
                cacheEncryptionKeys: true
            }
        },
        techOrder: ['html5'],
        sources: []
    };
    
    // Use HLS URL if available, otherwise fall back to direct stream URL
    if (props.hlsUrls && props.hlsUrls.master) {
        options.sources = [{
            src: props.hlsUrls.master,
            type: 'application/x-mpegURL'
        }];
    } else if (props.streamUrl) {
        // Determine type based on URL extension
        const type = props.streamUrl.includes('.m3u8') ? 'application/x-mpegURL' : 
                    props.streamUrl.includes('.flv') ? 'video/x-flv' : 
                    'video/mp4';
        options.sources = [{
            src: props.streamUrl,
            type: type
        }];
    }
    
    // Create player instance
    player = videojs(videoPlayer.value, options);
    
    // Initialize quality selector plugin if HLS
    if (props.hlsUrls || props.streamUrl.includes('.m3u8')) {
        player.ready(() => {
            // Initialize quality levels
            player.qualityLevels();
            
            // Add HLS quality selector UI
            player.hlsQualitySelector({
                displayCurrentQuality: true,
                placementIndex: 0,
                vjsIconClass: 'vjs-icon-hd'
            });
        });
    }
    
    // Event listeners
    player.on('error', (error) => {
        console.error('Video.js error:', error);
        emit('error', error);
        
        // Auto-retry logic for live streams
        if (props.isLive) {
            setTimeout(() => {
                if (player && !player.isDisposed()) {
                    player.src(options.sources[0]);
                    player.load();
                    player.play().catch(e => console.error('Retry play failed:', e));
                }
            }, 5000);
        }
    });
    
    player.on('playing', () => {
        emit('playing');
    });
    
    player.on('pause', () => {
        emit('pause');
    });
    
    player.on('ended', () => {
        emit('ended');
    });
    
    player.on('loadedmetadata', () => {
        emit('loadedmetadata');
        
        // For live streams, seek to live edge
        if (props.isLive && player.liveTracker) {
            player.liveTracker.seekToLiveEdge();
        }
    });
    
    // Track quality changes
    if (player.qualityLevels) {
        const qualityLevels = player.qualityLevels();
        
        qualityLevels.on('change', () => {
            let currentQuality = null;
            for (let i = 0; i < qualityLevels.length; i++) {
                if (qualityLevels[i].enabled) {
                    currentQuality = {
                        height: qualityLevels[i].height,
                        width: qualityLevels[i].width,
                        bitrate: qualityLevels[i].bitrate,
                        index: i
                    };
                    break;
                }
            }
            emit('qualityChanged', currentQuality);
        });
    }
    
    // Handle live stream specific features
    if (props.isLive) {
        // Add live indicator
        player.on('liveui', () => {
            console.log('Live UI activated');
        });
        
        // Keep stream at live edge
        player.on('play', () => {
            if (player.liveTracker && !player.liveTracker.atLiveEdge()) {
                player.liveTracker.seekToLiveEdge();
            }
        });
    }
};

// Watch for stream URL changes
watch(() => props.streamUrl, (newUrl) => {
    if (player && newUrl) {
        const type = newUrl.includes('.m3u8') ? 'application/x-mpegURL' : 
                    newUrl.includes('.flv') ? 'video/x-flv' : 
                    'video/mp4';
        player.src({ src: newUrl, type: type });
        player.load();
        if (props.autoplay) {
            player.play().catch(e => console.error('Play failed:', e));
        }
    }
});

watch(() => props.hlsUrls, (newUrls) => {
    if (player && newUrls && newUrls.master) {
        player.src({ src: newUrls.master, type: 'application/x-mpegURL' });
        player.load();
        if (props.autoplay) {
            player.play().catch(e => console.error('Play failed:', e));
        }
    }
});

onMounted(() => {
    nextTick(() => {
        initializePlayer();
    });
});

onBeforeUnmount(() => {
    if (player) {
        player.dispose();
        player = null;
    }
});

// Expose player methods
defineExpose({
    play: () => player?.play(),
    pause: () => player?.pause(),
    mute: () => player?.muted(true),
    unmute: () => player?.muted(false),
    setVolume: (vol) => player?.volume(vol),
    seekToLive: () => player?.liveTracker?.seekToLiveEdge(),
    getPlayer: () => player
});
</script>

<style scoped>
.video-js-container {
    width: 100%;
    height: 100%;
    position: relative;
}

/* Custom Video.js theme overrides */
:deep(.video-js) {
    background-color: #000;
}

:deep(.vjs-big-play-button) {
    border-radius: 50%;
    width: 80px;
    height: 80px;
    line-height: 80px;
    margin-top: -40px;
    margin-left: -40px;
}

:deep(.vjs-control-bar) {
    background-color: rgba(0, 0, 0, 0.7);
}

:deep(.vjs-live-control) {
    color: #ff0000;
}

:deep(.vjs-live-control.vjs-at-live-edge) {
    color: #00ff00;
}

/* Quality selector styling */
:deep(.vjs-quality-selector) {
    font-size: 1em;
}

:deep(.vjs-menu-button-popup .vjs-menu) {
    left: auto;
    right: -1em;
}
</style>