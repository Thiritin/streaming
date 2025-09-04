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
    },
    isInternalNavigation: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['error', 'playing', 'pause', 'ended', 'loadedmetadata', 'qualityChanged', 'qualityLevelsAvailable', 'useractive', 'userinactive', 'volumechange', 'toggleStats']);

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

// Load Chromecast plugin and SDK in correct order
const loadChromecastDependencies = () => {
    return new Promise(async (resolve) => {
        try {
            // First, import the Chromecast plugin
            await import('@silvermine/videojs-chromecast/dist/silvermine-videojs-chromecast');
            console.log('Chromecast plugin loaded successfully');

            // Check if the plugin registered correctly
            if (videojs.getPlugin && videojs.getPlugin('chromecast')) {
                console.log('Chromecast plugin is registered with Video.js');
            } else {
                console.warn('Chromecast plugin did not register properly');
            }

            // Then load the Cast SDK (must be after plugin)
            if (!window.chrome || !window.chrome.cast) {
                const script = document.createElement('script');
                script.src = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';
                script.onload = () => {
                    console.log('Cast SDK loaded');
                    // Initialize Cast API when it's available
                    window['__onGCastApiAvailable'] = function(isAvailable) {
                        if (isAvailable) {
                            console.log('Cast API is available');
                        }
                    };
                    resolve();
                };
                script.onerror = () => {
                    console.error('Failed to load Cast SDK');
                    resolve(); // Continue anyway
                };
                document.head.appendChild(script);
            } else {
                console.log('Cast SDK already loaded');
                resolve();
            }
        } catch (error) {
            console.error('Failed to load Chromecast dependencies:', error);
            resolve(); // Continue without Chromecast
        }
    });
};

const initializePlayer = async () => {
    if (!videoPlayer.value) return;

    // Load Chromecast dependencies in correct order
    await loadChromecastDependencies();

    // Check for saved volume preferences first
    const savedVolume = getCookie('player_volume');
    const savedMuted = getCookie('player_muted');

    // Smart autoplay logic:
    // - If internal navigation (user already interacted), try unmuted autoplay
    // - If direct page load, use muted autoplay for reliability
    let initialMuted;
    let autoplayMode;

    if (props.autoplay) {
        if (props.isInternalNavigation) {
            // User navigated from within the site - browser should allow unmuted autoplay
            initialMuted = savedMuted !== null ? savedMuted === 'true' : false;
            autoplayMode = 'play'; // Try unmuted autoplay
            console.log('Internal navigation - attempting unmuted autoplay');
        } else {
            // Direct page load - must use muted autoplay
            initialMuted = true;
            autoplayMode = 'muted';
            console.log('Direct page load - using muted autoplay');
        }
    } else {
        initialMuted = savedMuted !== null ? savedMuted === 'true' : props.muted;
        autoplayMode = false;
    }

    // Video.js options
    const options = {
        autoplay: autoplayMode,
        controls: props.controls,
        muted: initialMuted,
        volume: savedVolume !== null ? parseFloat(savedVolume) : 1.0,
        fluid: true,
        responsive: true,
        preload: 'auto',
        playsinline: true, // Important for mobile
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
                fastQualityChange: false, // Disable fast quality change for smoother transitions
                handlePartialData: true,
                useBandwidthFromLocalStorage: true,
                limitRenditionByPlayerDimensions: true,
                useDevicePixelRatio: true,
                bandwidth: 5000000, // Start with 5 Mbps - more conservative initial estimate
                enableLowInitialPlaylist: false, // Start with best quality if bandwidth allows
                allowSeeksWithinUnsafeLiveWindow: false,
                customTagParsers: [],
                customTagMappers: [],
                experimentalBufferBasedABR: true, // Enable buffer-based ABR for smoother transitions
                experimentalLLHLS: false,
                cacheEncryptionKeys: true,
                maxPlaylistRetries: 3,
                // ABR algorithm settings for smoother switching
                abrEwmaFastLive: 3.0, // Faster adaptation for live
                abrEwmaSlowLive: 9.0,
                abrEwmaFastVoD: 3.0,
                abrEwmaSlowVoD: 9.0,
                abrBandwidthEstimator: 0.6, // Conservative bandwidth estimation
                abrEwmaDefaultEstimate: 5000000, // 5 Mbps default
                // Buffer settings for smoother playback
                bufferBasedABR: true,
                experimentalExactManifestTimings: true,
                maxGoalBufferLength: 30, // Keep 30 seconds buffered
                goalBufferLength: 20, // Try to maintain 10 seconds
                maxBufferLength: 30,
                bufferPruneAhead: 1,
                renditionMixin: {
                    excludeUntil: Infinity // Don't exclude renditions permanently
                }
            }
        },
        techOrder: ['chromecast', 'html5'], // Chromecast must be first in techOrder
        sources: [],
        // Chromecast plugin configuration
        plugins: {
            chromecast: {
                receiverAppID: null, // Uses default receiver
                addButtonToControlBar: true, // Automatically add button to control bar
                buttonPositionIndex: 0, // Position in control bar (0 = after play button)
                requestTitleFn: function(source) {
                    // Customize the title shown on Chromecast
                    return 'Live Stream';
                },
                requestSubtitleFn: function(source) {
                    // Customize subtitle shown on Chromecast  
                    return 'Eurofurence Streaming';
                }
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
        console.log('Player is ready');

        // Explicitly attempt to play if autoplay is enabled
        // This helps ensure playback starts even if the autoplay option doesn't trigger
        if (props.autoplay) {
            // Wait a moment for the player to fully initialize
            setTimeout(() => {
                if (props.isInternalNavigation) {
                    // Try unmuted first for internal navigation
                    console.log('Attempting unmuted autoplay (internal navigation)...');
                    player.play().then(() => {
                        console.log('Unmuted autoplay successful!');
                    }).catch(e => {
                        console.error('Unmuted autoplay failed, falling back to muted:', e);
                        // Fallback to muted autoplay
                        player.muted(true);
                        player.play().then(() => {
                            console.log('Muted autoplay successful');
                            showUnmutePrompt.value = true; // Show unmute prompt
                        }).catch(err => {
                            console.error('Muted autoplay also failed:', err);
                        });
                    });
                } else {
                    // Direct load - go straight to muted autoplay
                    console.log('Attempting muted autoplay (direct load)...');
                    player.muted(true);
                    player.play().then(() => {
                        console.log('Muted autoplay successful');
                    }).catch(e => {
                        console.error('Muted autoplay failed:', e);
                    });
                }
            }, 100);
        }

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
            console.log('Chromecast plugin is available on player');
            
            // Check for Chromecast button in control bar
            setTimeout(() => {
                const chromecastButton = player.controlBar.getChild('ChromecastButton');
                if (chromecastButton) {
                    console.log('Chromecast button found in control bar');
                    // Make button more visible if hidden
                    chromecastButton.show();
                } else {
                    console.warn('Chromecast button not found in control bar');
                    // Try to manually add the button if it's missing
                    if (videojs.getPlugin('chromecast')) {
                        console.log('Attempting to manually initialize Chromecast button...');
                        player.chromecast();
                    }
                }
            }, 1000); // Wait for control bar to be fully initialized
            
            // Listen for Chromecast events
            player.on('chromecastConnected', () => {
                console.log('Connected to Chromecast device');
            });
            
            player.on('chromecastDisconnected', () => {
                console.log('Disconnected from Chromecast device');
            });
            
            player.on('chromecastDevicesAvailable', () => {
                console.log('Chromecast devices are available');
            });
            
            player.on('chromecastDevicesUnavailable', () => {
                console.log('No Chromecast devices available');
            });
        } else {
            console.warn('Chromecast plugin not available on player');
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
        // Add quality selector for adaptive streams using master.m3u8
        const isAdaptiveStream = props.streamUrl && props.streamUrl.includes('master.m3u8');

        if (isAdaptiveStream && player.qualityLevels) {
            // For adaptive streams, create a quality selector that controls the quality levels
            const MenuButton = videojs.getComponent('MenuButton');
            const MenuItem = videojs.getComponent('MenuItem');
            const qualityLevels = player.qualityLevels();

            class AdaptiveQualityMenuItem extends MenuItem {
                constructor(player, options) {
                    super(player, options);
                    this.qualityLevel = options.qualityLevel;
                    this.qualityIndex = options.qualityIndex;
                    this.isAuto = options.isAuto || false;
                }

                handleClick() {
                    const qualityLevels = this.player().qualityLevels();

                    if (this.isAuto) {
                        // Enable all quality levels for auto mode
                        for (let i = 0; i < qualityLevels.length; i++) {
                            qualityLevels[i].enabled = true;
                        }
                        console.log('Switched to Auto quality mode');
                    } else {
                        // Disable all quality levels except the selected one
                        for (let i = 0; i < qualityLevels.length; i++) {
                            qualityLevels[i].enabled = (i === this.qualityIndex);
                        }
                        console.log(`Locked to quality: ${this.qualityLevel.height}p`);
                    }

                    // Update menu items to show selected
                    const qualityMenu = this.player().controlBar.getChild('adaptiveQualityMenuButton');
                    if (qualityMenu && qualityMenu.menu) {
                        qualityMenu.menu.children().forEach(item => {
                            item.selected(item === this);
                        });
                    }

                    // Emit quality change event
                    emit('qualityChanged', this.isAuto ? 'Auto' : `${this.qualityLevel.height}p`);
                }
            }

            class AdaptiveQualityMenuButton extends MenuButton {
                constructor(player, options) {
                    super(player, options);
                    this.controlText('Quality');
                    this.addClass('vjs-quality-menu-button');

                    // Update button when quality levels are available
                    const qualityLevels = player.qualityLevels();
                    qualityLevels.on('addqualitylevel', () => {
                        this.update();
                    });
                }

                buildCSSClass() {
                    return `vjs-quality-menu-button ${super.buildCSSClass()}`;
                }

                createItems() {
                    const items = [];
                    const qualityLevels = this.player().qualityLevels();

                    // Add Auto option
                    const autoItem = new AdaptiveQualityMenuItem(this.player(), {
                        label: 'Auto',
                        isAuto: true
                    });
                    autoItem.selected(true); // Auto is selected by default
                    items.push(autoItem);

                    // Add quality options from the master playlist
                    const qualities = [];
                    for (let i = 0; i < qualityLevels.length; i++) {
                        const level = qualityLevels[i];
                        qualities.push({
                            level: level,
                            index: i,
                            height: level.height,
                            bitrate: level.bitrate
                        });
                    }

                    // Sort by height (quality) descending
                    qualities.sort((a, b) => b.height - a.height);

                    // Add menu items for each quality
                    qualities.forEach(quality => {
                        const label = quality.height === 1080 ? '1080p' :
                                     quality.height === 720 ? '720p' :
                                     quality.height === 480 ? '480p' :
                                     `${quality.height}p`;

                        const item = new AdaptiveQualityMenuItem(this.player(), {
                            label: label,
                            qualityLevel: quality.level,
                            qualityIndex: quality.index,
                            isAuto: false
                        });

                        items.push(item);
                    });

                    return items;
                }
            }

            // Register and add the adaptive quality menu button
            videojs.registerComponent('AdaptiveQualityMenuButton', AdaptiveQualityMenuButton);

            // Wait for quality levels to be populated before adding the button
            const addQualityButton = () => {
                if (qualityLevels.length > 0) {
                    player.controlBar.addChild('AdaptiveQualityMenuButton', {}, player.controlBar.children().length - 2);
                    console.log('Adaptive quality selector added with', qualityLevels.length, 'qualities');
                } else {
                    // Wait and try again if no quality levels yet
                    setTimeout(addQualityButton, 500);
                }
            };

            // Start checking for quality levels
            addQualityButton();

        }
    });


    // Event listeners
    player.on('error', (error) => {
        console.error('Video.js error:', error);
        emit('error', error);
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

        console.log('Metadata loaded');

        // For live streams, seek to live edge
        if (props.isLive && player.liveTracker) {
            player.liveTracker.seekToLiveEdge();
        }

        // Try autoplay again when metadata is loaded
        if (props.autoplay && player.paused()) {
            if (props.isInternalNavigation && !player.muted()) {
                // Try unmuted first for internal navigation
                console.log('Attempting unmuted autoplay on metadata...');
                player.play().catch(e => {
                    console.error('Unmuted autoplay failed on metadata, trying muted:', e);
                    player.muted(true);
                    player.play().catch(err => console.error('Muted autoplay on metadata failed:', err));
                });
            } else {
                // Already muted or direct load
                console.log('Attempting autoplay on metadata loaded...');
                player.muted(true);
                player.play().then(() => {
                    console.log('Autoplay on metadata successful');
                }).catch(e => {
                    console.error('Autoplay on metadata failed:', e);
                });
            }
        }
    });

    // Also try on loadeddata event
    player.on('loadeddata', () => {
        console.log('Data loaded');

        if (props.autoplay && player.paused()) {
            // Skip if already trying in other events
            if (!player.muted() && !props.isInternalNavigation) {
                // Should be muted for direct load
                player.muted(true);
            }
            console.log('Attempting autoplay on data loaded...');
            player.play().catch(e => {
                if (!player.muted()) {
                    console.error('Unmuted play failed on data loaded, trying muted:', e);
                    player.muted(true);
                    player.play().catch(err => console.error('Muted play on data loaded failed:', err));
                }
            });
        }
    });

    // Try on canplay event - fires when playback can start
    player.on('canplay', () => {
        console.log('Can play');

        if (props.autoplay && player.paused()) {
            // Last attempt - ensure we try to play
            console.log('Final autoplay attempt on canplay...');
            player.play().catch(e => {
                if (!player.muted()) {
                    console.error('Unmuted play failed on canplay, trying muted:', e);
                    player.muted(true);
                    player.play().catch(err => console.error('All autoplay attempts failed:', err));
                }
            });
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

    // Track quality changes for adaptive streams
    if (player.qualityLevels) {
        const qualityLevels = player.qualityLevels();

        // Monitor when quality levels are added (when playlist loads)
        qualityLevels.on('addqualitylevel', (event) => {
            const level = event.qualityLevel;
            console.log(`Quality level added: ${level.height}p @ ${level.bitrate} bps`);
        });

        // Monitor quality changes
        qualityLevels.on('change', () => {
            let currentQuality = null;
            let availableQualities = [];

            for (let i = 0; i < qualityLevels.length; i++) {
                const level = qualityLevels[i];
                availableQualities.push({
                    height: level.height,
                    width: level.width,
                    bitrate: level.bitrate,
                    enabled: level.enabled,
                    index: i
                });

                // Find the currently selected quality (enabled = true means it's selected)
                if (level.enabled) {
                    currentQuality = {
                        height: level.height,
                        width: level.width,
                        bitrate: level.bitrate,
                        index: i,
                        label: `${level.height}p`
                    };
                }
            }

            if (currentQuality) {
                console.log(`Quality switched to: ${currentQuality.height}p @ ${currentQuality.bitrate} bps`);
                emit('qualityChanged', currentQuality);
            }

            // Also emit available qualities for UI display
            emit('qualityLevelsAvailable', availableQualities);
        });

        // Monitor the actual representation switch (when quality actually changes during playback)
        if (player.tech_ && player.tech_.vhs) {
            player.tech_.vhs.representations().forEach((rep, index) => {
                rep.on('enabled', () => {
                    console.log(`Representation ${index} enabled: ${rep.height}p @ ${rep.bandwidth} bps`);
                });
            });
        }
    }

    // Note: We're using our custom quality selector instead of the built-in HLS quality selector
    // This gives us more control over the UI and behavior

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

/* Chromecast button styling */
.video-js .vjs-chromecast-button {
    display: flex !important;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

/* Ensure Chromecast icon is visible */
.video-js .vjs-chromecast-button .vjs-icon-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
}

.video-js .vjs-chromecast-button .vjs-icon-placeholder:before {
    font-size: 1.8em;
}

/* Chromecast button hover state */
.video-js .vjs-chromecast-button:hover {
    color: oklch(71.68% 0.127 181.62); /* primary-300 */
}

/* Chromecast connected state */
.video-js .vjs-chromecast-button.vjs-chromecast-casting {
    color: oklch(53.86% 0.096 181.61); /* primary-500 */
}

/* Chromecast menu styling */
.video-js .vjs-chromecast-button .vjs-menu {
    background: oklch(23.56% 0.035 181.16); /* primary-900 */
    border: 1px solid oklch(32.74% 0.055 181.17); /* primary-800 */
}

.video-js .vjs-chromecast-button .vjs-menu-item {
    color: oklch(80.03% 0.142 181.59); /* primary-200 */
}

.video-js .vjs-chromecast-button .vjs-menu-item:hover {
    background: oklch(44.24% 0.078 181.52); /* primary-600 */
}
</style>
