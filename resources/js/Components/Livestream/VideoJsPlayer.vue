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
import 'videojs-hotkeys';

// Make videojs available globally for plugins that require it
window.videojs = videojs;

// Import and register quality levels plugin
import qualityLevels from 'videojs-contrib-quality-levels';
if (!videojs.getPlugin('qualityLevels')) {
    videojs.registerPlugin('qualityLevels', qualityLevels);
}

// Import HLS quality selector plugin - it auto-registers
import 'videojs-hls-quality-selector';

// Import Chromecast plugin CSS (JS will be loaded dynamically)
import '@silvermine/videojs-chromecast/dist/silvermine-videojs-chromecast.css';

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

const emit = defineEmits(['error', 'playing', 'pause', 'ended', 'loadedmetadata', 'qualityChanged', 'useractive', 'userinactive', 'volumechange', 'toggleStats']);

const videoPlayer = ref(null);
let player = null;

// Cookie utility functions
const setCookie = (name, value, days = 365) => {
    const expires = new Date();
    expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = `${name}=${value};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
};

const getCookie = (name) => {
    const nameEQ = name + "=";
    const ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
};

// Load Google Cast SDK
const loadCastSDK = () => {
    return new Promise((resolve) => {
        if (window.chrome && window.chrome.cast) {
            console.log('Cast SDK already loaded');
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';
        script.onload = () => {
            console.log('Cast SDK loaded');
            resolve();
        };
        script.onerror = () => {
            console.error('Failed to load Cast SDK');
            resolve(); // Continue anyway
        };
        document.head.appendChild(script);
    });
};

const initializePlayer = async () => {
    if (!videoPlayer.value) return;

    // Load Cast SDK first
    await loadCastSDK();

    // Dynamically import Chromecast plugin after videojs is available
    try {
        await import('@silvermine/videojs-chromecast/dist/silvermine-videojs-chromecast');
        console.log('Chromecast plugin loaded successfully');

        // Check if the plugin registered correctly
        if (videojs.getPlugin && videojs.getPlugin('chromecast')) {
            console.log('Chromecast plugin is registered with Video.js');
        } else {
            console.warn('Chromecast plugin did not register properly');
        }
    } catch (error) {
        console.error('Failed to load Chromecast plugin:', error);
    }

    // Check for saved volume preferences first
    const savedVolume = getCookie('player_volume');
    const savedMuted = getCookie('player_muted');

    // For autoplay, we must start muted due to browser policies
    // We'll only unmute on actual user interaction to avoid pausing
    const initialMuted = props.autoplay ? true : (savedMuted !== null ? savedMuted === 'true' : props.muted);

    // Video.js options
    const options = {
        autoplay: props.autoplay ? 'muted' : false, // Always use muted autoplay for reliability
        controls: props.controls,
        muted: initialMuted,
        volume: savedVolume !== null ? parseFloat(savedVolume) : 1.0,
        fluid: true,
        responsive: true,
        preload: 'auto',
        liveui: props.isLive,
        liveTracker: {
            trackingThreshold: 30,  // Allow 30 seconds behind before considering "not live"
            liveTolerance: 15,      // 15 seconds from edge is considered "at live"
            pauseTracking: false    // Don't pause tracking when seeking
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
                allowSeeksWithinUnsafeLiveWindow: true,
                customTagParsers: [],
                customTagMappers: [],
                experimentalBufferBasedABR: false,
                experimentalLLHLS: false,
                cacheEncryptionKeys: true
            }
        },
        techOrder: ['html5'],
        sources: [],
        // Chromecast plugin options
        plugins: {
            chromecast: {
                receiverAppID: null, // Uses default receiver if null
                addButtonToControlBar: true
            }
        }
    };

    // Use HLS URL if available
    if (props.streamUrl) {
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

    // No need for complex autoplay handling - Video.js handles it with 'muted' option

    // Initialize plugins when player is ready
    player.ready(() => {
        // Initialize hotkeys plugin after player is ready
        if (typeof player.hotkeys === 'function') {
            player.hotkeys({
                volumeStep: 0.1,
                seekStep: 5,
                enableModifiersForNumbers: false,
                enableMute: true,
                enableVolumeScroll: false, // Disable for better mobile support
                enableHoverScroll: false,
                enableFullscreen: true,
                enableNumbers: false, // Disable for live streams
                alwaysCaptureHotkeys: true,
                captureDocumentHotkeys: false,
                documentHotkeysFocusElementFilter: (e) => {
                    const tagName = e.tagName.toLowerCase();
                    return tagName !== 'input' && tagName !== 'textarea';
                },
                customKeys: {
                    // Play/Pause with space or K
                    playPauseKey: {
                        key: function(event) {
                            return (event.which === 32 || event.which === 75);
                        },
                        handler: function(player, options, event) {
                            event.preventDefault();
                            if (player.paused()) {
                                player.play();
                            } else {
                                player.pause();
                            }
                        }
                    },
                    // Volume up with up arrow
                    volumeUpKey: {
                        key: function(event) {
                            return event.which === 38;
                        },
                        handler: function(player, options) {
                            player.volume(Math.min(1, player.volume() + options.volumeStep));
                        }
                    },
                    // Volume down with down arrow
                    volumeDownKey: {
                        key: function(event) {
                            return event.which === 40;
                        },
                        handler: function(player, options) {
                            player.volume(Math.max(0, player.volume() - options.volumeStep));
                        }
                    },
                    // Mute with M
                    muteKey: {
                        key: function(event) {
                            return event.which === 77;
                        },
                        handler: function(player) {
                            player.muted(!player.muted());
                        }
                    },
                    // Fullscreen with F
                    fullscreenKey: {
                        key: function(event) {
                            return event.which === 70;
                        },
                        handler: function(player) {
                            if (player.isFullscreen()) {
                                player.exitFullscreen();
                            } else {
                                player.requestFullscreen();
                            }
                        }
                    },
                    // Stats overlay with I
                    statsKey: {
                        key: function(event) {
                            return event.which === 73;
                        },
                        handler: function() {
                            emit('toggleStats');
                        }
                    }
                }
            });
            console.log('Hotkeys plugin initialized');
        } else {
            console.warn('Hotkeys plugin not available');
        }

        // For live streams, hide playback rate control as it doesn't work
        if (props.isLive) {
            if (player.controlBar.playbackRateMenuButton) {
                player.controlBar.playbackRateMenuButton.hide();
            }
        } else {
            // Only show playback rate for VOD
            if (player.controlBar.playbackRateMenuButton) {
                player.controlBar.playbackRateMenuButton.show();
                player.controlBar.playbackRateMenuButton.playbackRates([0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]);
            }
        }
        // Log initial volume state
        console.log('Player ready - Initial volume:', player.volume(), 'Muted:', player.muted());

        // Check if Chromecast plugin initialized
        if (typeof player.chromecast === 'function') {
            console.log('Chromecast method is available on player');
            try {
                // Initialize chromecast explicitly
                player.chromecast();
                console.log('Chromecast plugin initialized on player');

                // Check for Chromecast button
                const chromecastButton = player.controlBar.getChild('chromecastButton');
                if (chromecastButton) {
                    console.log('Chromecast button found in control bar');
                } else {
                    console.warn('Chromecast button not found in control bar');
                }
            } catch (error) {
                console.error('Error initializing Chromecast:', error);
            }
        } else {
            console.warn('Chromecast method not available on player');
        }

        // Set up unmute on first user interaction if player is muted
        // and user's preference was unmuted
        if (player.muted()) {
            let hasInteracted = false;

            const handleFirstInteraction = () => {
                if (hasInteracted) return;
                hasInteracted = true;

                // Check if user's saved preference was unmuted
                if (savedMuted === 'false' || savedMuted === null) {
                    player.muted(false);
                    console.log('Unmuted on first user interaction');
                }

                // Clean up listeners
                document.removeEventListener('click', handleFirstInteraction);
                document.removeEventListener('touchstart', handleFirstInteraction);
            };

            // Listen for any interaction on the document
            document.addEventListener('click', handleFirstInteraction, { once: true });
            document.addEventListener('touchstart', handleFirstInteraction, { once: true });
        }
        // Add manual quality selector for our separate HLS streams
        if (props.hlsUrls && (props.hlsUrls.fhd || props.hlsUrls.hd || props.hlsUrls.sd)) {
            // Create custom quality menu button
            const MenuButton = videojs.getComponent('MenuButton');
            const MenuItem = videojs.getComponent('MenuItem');
            
            class QualityMenuItem extends MenuItem {
                constructor(player, options) {
                    super(player, options);
                    this.qualityUrl = options.qualityUrl;
                    this.qualityLabel = options.qualityLabel;
                }
                
                handleClick() {
                    // Get current time before switching
                    const currentTime = this.player().currentTime();
                    const wasPlaying = !this.player().paused();
                    
                    // Switch quality by changing source
                    this.player().src({
                        src: this.qualityUrl,
                        type: 'application/x-mpegURL'
                    });
                    
                    // When new source loads, seek to previous time and resume if playing
                    this.player().one('loadedmetadata', () => {
                        if (this.player().liveTracker) {
                            // For live streams, go to live edge
                            this.player().liveTracker.seekToLiveEdge();
                        } else {
                            // For VOD, seek to previous position
                            this.player().currentTime(currentTime);
                        }
                        
                        if (wasPlaying) {
                            this.player().play().catch(e => console.error('Play after quality change failed:', e));
                        }
                    });
                    
                    // Update menu items to show selected
                    const qualityMenu = this.player().controlBar.getChild('qualityMenuButton');
                    if (qualityMenu && qualityMenu.menu) {
                        qualityMenu.menu.children().forEach(item => {
                            item.selected(item === this);
                        });
                    }
                    
                    // Emit quality change event
                    emit('qualityChanged', this.qualityLabel);
                }
            }
            
            class QualityMenuButton extends MenuButton {
                constructor(player, options) {
                    super(player, options);
                    this.controlText('Quality');
                    
                    // Add the icon class for the quality button
                    this.addClass('vjs-quality-menu-button');
                }
                
                buildCSSClass() {
                    return `vjs-quality-menu-button ${super.buildCSSClass()}`;
                }
                
                createItems() {
                    const items = [];
                    
                    // Add quality options including auto/adaptive stream
                    const qualities = [
                        { url: props.streamUrl, label: 'Auto', key: 'auto' },
                        { url: props.hlsUrls.fhd, label: 'Full HD (1080p)', key: 'fhd' },
                        { url: props.hlsUrls.hd, label: 'HD (720p)', key: 'hd' },
                        { url: props.hlsUrls.sd, label: 'SD (480p)', key: 'sd' }
                    ];
                    
                    qualities.forEach(quality => {
                        if (quality.url) {
                            const item = new QualityMenuItem(this.player(), {
                                label: quality.label,
                                qualityUrl: quality.url,
                                qualityLabel: quality.label
                            });
                            
                            // Mark current quality as selected
                            if (quality.url === props.streamUrl) {
                                item.selected(true);
                            }
                            
                            items.push(item);
                        }
                    });
                    
                    return items;
                }
            }
            
            // Register and add the quality menu button
            videojs.registerComponent('QualityMenuButton', QualityMenuButton);
            player.controlBar.addChild('QualityMenuButton', {}, player.controlBar.children().length - 2);
            console.log('Manual quality selector added');
        }
    });

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

    // User activity events for controls visibility
    player.on('useractive', () => {
        emit('useractive');
    });

    player.on('userinactive', () => {
        emit('userinactive');
    });

    // Save volume changes to cookie and emit event
    player.on('volumechange', () => {
        const currentVolume = player.volume();
        const isMuted = player.muted();

        console.log('Volume changed - Volume:', currentVolume, 'Muted:', isMuted);

        // Always save the volume, even when muted
        setCookie('player_volume', currentVolume.toString());
        setCookie('player_muted', isMuted.toString());

        console.log('Saved to cookies - Volume:', currentVolume, 'Muted:', isMuted);

        // Emit volumechange event to parent
        emit('volumechange');
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

        // Don't automatically seek to live edge on play - let user control their position
        // The live tracker UI will show when behind live and user can click to go live
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
    if (player && newUrls && newUrls.stream) {
        player.src({ src: newUrls.stream, type: 'application/x-mpegURL' });
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
</style>

<style>
/* Global Video.js custom theme - colors only */

/* Quality button icon - HD text */
.vjs-quality-menu-button.vjs-menu-button .vjs-icon-placeholder:before {
    content: 'HD';
}

/* Progress bar colors */
.video-js .vjs-play-progress {
    background: linear-gradient(90deg, oklch(53.86% 0.096 181.61), oklch(62.48% 0.111 181.51)); /* primary-500 to primary-400 */
}

/* Volume level color */
.video-js .vjs-volume-level {
    background: linear-gradient(90deg, oklch(53.86% 0.096 181.61), oklch(62.48% 0.111 181.51)); /* primary-500 to primary-400 */
}

/* Control hover color */
.video-js .vjs-control:hover {
    color: oklch(71.68% 0.127 181.62); /* primary-300 */
}

/* Live indicator */
.video-js .vjs-live-control.vjs-at-live-edge {
    color: rgb(239, 68, 68);
}

.video-js .vjs-live-control.vjs-at-live-edge:before {
    content: '● ';
    color: rgb(239, 68, 68);
}

/* Selected menu item */
.video-js .vjs-menu-item.vjs-selected {
    background: oklch(53.86% 0.096 181.61 / 0.2); /* primary-500 with low opacity */
    color: oklch(80.03% 0.142 181.59); /* primary-200 */
}

/* Loading spinner color */
.video-js .vjs-loading-spinner:before,
.video-js .vjs-loading-spinner:after {
    border-color: oklch(53.86% 0.096 181.61) transparent transparent; /* primary-500 */
}
</style>
