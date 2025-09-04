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
                    
                    <!-- Video.js Player -->
                    <div class="video-js-container">
                        <video 
                            ref="videoPlayer"
                            class="video-js vjs-default-skin vjs-big-play-centered vjs-fluid"
                            :poster="recording.thumbnail_url"
                        ></video>
                    </div>
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
import videojs from 'video.js';
import 'video.js/dist/video-js.css';
import 'videojs-contrib-quality-levels';
import '@silvermine/videojs-chromecast/dist/silvermine-videojs-chromecast.css';

// Make videojs available globally for plugins
window.videojs = videojs;

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
let player = null;

// Load Chromecast dependencies
const loadChromecastDependencies = async () => {
    try {
        // Import Chromecast plugin first
        await import('@silvermine/videojs-chromecast/dist/silvermine-videojs-chromecast');
        console.log('Chromecast plugin loaded for recordings');
        
        // Then load Cast SDK if not already loaded
        if (!window.chrome || !window.chrome.cast) {
            return new Promise((resolve) => {
                const script = document.createElement('script');
                script.src = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';
                script.onload = () => {
                    console.log('Cast SDK loaded');
                    window['__onGCastApiAvailable'] = function(isAvailable) {
                        if (isAvailable) {
                            console.log('Cast API is available');
                        }
                    };
                    resolve();
                };
                script.onerror = () => {
                    console.error('Failed to load Cast SDK');
                    resolve();
                };
                document.head.appendChild(script);
            });
        }
    } catch (error) {
        console.error('Failed to load Chromecast dependencies:', error);
    }
};

// Custom Quality Selector Menu Button
class QualityMenuButton extends videojs.getComponent('MenuButton') {
    constructor(player, options) {
        super(player, options);
        this.controlText('Quality');
        
        // Update button text when quality changes
        const qualityLevels = player.qualityLevels();
        qualityLevels.on('change', () => {
            this.updateButtonText();
        });
    }
    
    createItems() {
        const items = [];
        const qualityLevels = this.player().qualityLevels();
        
        // Add Auto option
        items.push(new QualityMenuItem(this.player(), {
            label: 'Auto',
            value: 'auto',
            selected: true
        }));
        
        // Create a map to group quality levels by height
        const qualityMap = new Map();
        
        for (let i = 0; i < qualityLevels.length; i++) {
            const quality = qualityLevels[i];
            const height = quality.height;
            
            if (!height) continue; // Skip if no height info
            
            if (!qualityMap.has(height)) {
                qualityMap.set(height, []);
            }
            qualityMap.get(height).push(i);
        }
        
        // Create menu items for each unique quality
        const heights = Array.from(qualityMap.keys()).sort((a, b) => b - a);
        
        for (const height of heights) {
            let label;
            if (height >= 2160) {
                label = '4K';
            } else if (height >= 1080) {
                label = '1080p';
            } else if (height >= 720) {
                label = '720p';
            } else if (height >= 480) {
                label = '480p';
            } else if (height >= 360) {
                label = '360p';
            } else if (height >= 240) {
                label = '240p';
            } else {
                label = height + 'p';
            }
            
            items.push(new QualityMenuItem(this.player(), {
                label: label,
                value: qualityMap.get(height),  // Pass all indexes for this height
                height: height,
                qualityLevels: qualityLevels
            }));
        }
        
        return items;
    }
    
    updateButtonText() {
        const qualityLevels = this.player().qualityLevels();
        let activeLevel = null;
        let autoMode = true;
        
        // Check if we're in auto mode (all levels enabled) or manual mode
        let enabledCount = 0;
        for (let i = 0; i < qualityLevels.length; i++) {
            if (qualityLevels[i].enabled) {
                enabledCount++;
                if (!activeLevel) {
                    activeLevel = qualityLevels[i];
                }
            }
        }
        
        // If not all levels are enabled, we're in manual mode
        if (enabledCount > 0 && enabledCount < qualityLevels.length) {
            autoMode = false;
        }
        
        const placeholder = this.el().querySelector('.vjs-icon-placeholder');
        if (!placeholder) return;
        
        // Clear any existing content first
        placeholder.innerHTML = '';
        
        if (autoMode) {
            placeholder.textContent = 'Auto';
        } else if (activeLevel) {
            let label;
            if (activeLevel.height >= 2160) {
                label = '4K';
            } else if (activeLevel.height >= 1080) {
                label = '1080p';
            } else if (activeLevel.height >= 720) {
                label = '720p';
            } else if (activeLevel.height >= 480) {
                label = '480p';
            } else if (activeLevel.height >= 360) {
                label = '360p';
            } else if (activeLevel.height >= 240) {
                label = '240p';
            } else {
                label = activeLevel.height + 'p';
            }
            placeholder.textContent = label;
        } else {
            placeholder.textContent = 'Auto';
        }
        
        // Ensure consistent styling
        placeholder.style.fontSize = '0.9em';
        placeholder.style.fontWeight = 'normal';
    }
}

// Custom Quality Menu Item
class QualityMenuItem extends videojs.getComponent('MenuItem') {
    constructor(player, options) {
        super(player, options);
        this.qualityLevels = options.qualityLevels || player.qualityLevels();
        this.height = options.height;
        this.value = options.value;
        
        if (options.selected) {
            this.addClass('vjs-selected');
        }
    }
    
    handleClick() {
        const qualityLevels = this.qualityLevels;
        
        // Remove selected class from all items
        const menuButton = this.player().controlBar.getChild('QualityMenuButton');
        if (menuButton && menuButton.items) {
            menuButton.items.forEach(item => item.removeClass('vjs-selected'));
        }
        this.addClass('vjs-selected');
        
        if (this.value === 'auto') {
            // Enable all quality levels for auto mode
            for (let i = 0; i < qualityLevels.length; i++) {
                qualityLevels[i].enabled = true;
            }
        } else {
            // Disable all quality levels first
            for (let i = 0; i < qualityLevels.length; i++) {
                qualityLevels[i].enabled = false;
            }
            
            // Enable only the quality levels for the selected height
            if (Array.isArray(this.value)) {
                // Enable all indexes for this quality
                this.value.forEach(index => {
                    if (qualityLevels[index]) {
                        qualityLevels[index].enabled = true;
                    }
                });
            } else if (typeof this.value === 'number') {
                // Legacy single index support
                qualityLevels[this.value].enabled = true;
            }
        }
        
        // Update button text
        if (menuButton) {
            menuButton.updateButtonText();
        }
    }
}

// Register custom components
videojs.registerComponent('QualityMenuButton', QualityMenuButton);
videojs.registerComponent('QualityMenuItem', QualityMenuItem);

// Function to create quality selector
const createQualitySelector = (player, qualityLevels) => {
    // Don't add if already exists
    if (player.controlBar.getChild('QualityMenuButton')) {
        return;
    }
    
    // Add the quality selector button to control bar
    const menuButton = player.controlBar.addChild('QualityMenuButton', {});
    
    // Position it before the fullscreen button
    const fullscreenIndex = player.controlBar.children().indexOf(player.controlBar.getChild('FullscreenToggle'));
    if (fullscreenIndex > 0) {
        player.controlBar.el().insertBefore(
            menuButton.el(),
            player.controlBar.children()[fullscreenIndex].el()
        );
    }
    
    // Add custom class for styling
    menuButton.addClass('vjs-quality-selector');
    const placeholder = menuButton.el().querySelector('.vjs-icon-placeholder');
    if (placeholder) {
        placeholder.textContent = 'Auto';
        // Remove any default content that might be causing overlap
        placeholder.style.fontSize = '0.9em';
        placeholder.style.fontWeight = 'normal';
    }
    
    console.log('Custom quality selector added');
};

const initializePlayer = async () => {
    if (!videoPlayer.value) return;
    
    // Load Chromecast dependencies first
    await loadChromecastDependencies();
    
    // Video.js options
    const options = {
        controls: true,
        autoplay: true,
        preload: 'auto',
        fluid: true,
        responsive: true,
        techOrder: ['chromecast', 'html5'], // Chromecast must be first
        html5: {
            vhs: {
                // Enable adaptive bitrate selection
                enableLowInitialPlaylist: true,
                smoothQualityChange: true,
                overrideNative: true
            }
        },
        sources: [{
            src: props.recording.m3u8_url,
            type: 'application/x-mpegURL'
        }],
        controlBar: {
            playToggle: true,
            volumePanel: {
                inline: false
            },
            currentTimeDisplay: true,
            timeDivider: true,
            durationDisplay: true,
            progressControl: true,
            liveDisplay: false,
            remainingTimeDisplay: false,
            customControlSpacer: false,
            playbackRateMenuButton: {
                playbackRates: [0.5, 0.75, 1, 1.25, 1.5, 2]
            },
            chaptersButton: false,
            descriptionsButton: false,
            subsCapsButton: false,
            audioTrackButton: false,
            fullscreenToggle: true,
            pictureInPictureToggle: true
        },
        // Chromecast plugin configuration
        plugins: {
            chromecast: {
                receiverAppID: null, // Uses default receiver
                addButtonToControlBar: true,
                buttonPositionIndex: 0, // After play button
                requestTitleFn: function() {
                    return props.recording.title || 'Recording';
                },
                requestSubtitleFn: function() {
                    return props.recording.description || 'Eurofurence Recording';
                }
            }
        }
    };
    
    // Initialize Video.js player
    player = videojs(videoPlayer.value, options, function() {
        // Player is ready
        console.log('Video.js player ready');
        
        // Create custom quality selector
        const player = this;
        
        // Setup quality selector when levels are available
        const setupQualitySelector = () => {
            const qualityLevels = player.qualityLevels();
            
            if (qualityLevels && qualityLevels.length > 0) {
                if (!player.controlBar.getChild('QualityMenuButton')) {
                    console.log(`Found ${qualityLevels.length} quality levels`);
                    createQualitySelector(player, qualityLevels);
                }
                return true;
            }
            return false;
        };
        
        // Try to setup immediately
        if (!setupQualitySelector()) {
            // If not available yet, listen for them
            const qualityLevels = player.qualityLevels();
            
            if (qualityLevels) {
                console.log('Waiting for quality levels to be added...');
                
                // Listen for the first quality level
                const onLevelAdded = () => {
                    console.log('Quality level added, total:', qualityLevels.length);
                    
                    // Wait a bit for all levels to be added
                    setTimeout(() => {
                        if (setupQualitySelector()) {
                            // Remove listener once setup is done
                            qualityLevels.off('addqualitylevel', onLevelAdded);
                        }
                    }, 500);
                };
                
                qualityLevels.on('addqualitylevel', onLevelAdded);
                
                // Also try on loadedmetadata
                player.on('loadedmetadata', () => {
                    setTimeout(() => setupQualitySelector(), 1000);
                });
            }
        }
        
        // Event listeners
        this.on('loadstart', () => {
            loading.value = true;
            error.value = false;
        });
        
        this.on('loadeddata', () => {
            loading.value = false;
            error.value = false;
        });
        
        this.on('error', (e) => {
            loading.value = false;
            error.value = true;
            const err = this.error();
            
            if (err) {
                switch(err.code) {
                    case 1: // MEDIA_ERR_ABORTED
                        errorMessage.value = 'Video playback was aborted.';
                        break;
                    case 2: // MEDIA_ERR_NETWORK
                        errorMessage.value = 'Network error. Please check your connection.';
                        break;
                    case 3: // MEDIA_ERR_DECODE
                        errorMessage.value = 'Video decoding error.';
                        break;
                    case 4: // MEDIA_ERR_SRC_NOT_SUPPORTED
                        errorMessage.value = 'Video format not supported.';
                        break;
                    default:
                        errorMessage.value = 'An error occurred while playing the video.';
                }
            }
            
            console.error('Video.js error:', err);
        });
        
        // Add quality change listener
        try {
            if (this.qualityLevels) {
                this.qualityLevels().on('change', () => {
                    console.log('Quality level changed');
                });
            }
        } catch (e) {
            console.warn('Quality levels not available:', e);
        }
        
        // Check for Chromecast plugin
        if (typeof this.chromecast === 'function') {
            console.log('Chromecast plugin available for recordings');
            
            // Check for button after a delay
            setTimeout(() => {
                const chromecastButton = this.controlBar.getChild('ChromecastButton');
                if (chromecastButton) {
                    console.log('Chromecast button added to recording player');
                    chromecastButton.show();
                } else {
                    console.warn('Chromecast button not found, attempting manual init');
                    if (videojs.getPlugin('chromecast')) {
                        this.chromecast();
                    }
                }
            }, 1000);
            
            // Chromecast events
            this.on('chromecastConnected', () => {
                console.log('Recording connected to Chromecast');
            });
            
            this.on('chromecastDisconnected', () => {
                console.log('Recording disconnected from Chromecast');
            });
        } else {
            console.warn('Chromecast plugin not available for recordings');
        }
    });
};

const retryPlayback = () => {
    error.value = false;
    loading.value = true;
    if (player) {
        player.src({
            src: props.recording.m3u8_url,
            type: 'application/x-mpegURL'
        });
        player.load();
        player.play().catch(e => {
            console.log('Autoplay prevented:', e);
        });
    } else {
        initializePlayer();
    }
};

onMounted(async () => {
    await initializePlayer();
});

onUnmounted(() => {
    if (player) {
        player.dispose();
        player = null;
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

<style scoped>
/* Custom Video.js Theme */
:deep(.video-js) {
    border-radius: 0.5rem;
    overflow: hidden;
}

/* Control bar styling */
:deep(.video-js .vjs-control-bar) {
    background: linear-gradient(to top, rgba(0, 0, 0, 0.9), transparent);
}

/* Quality selector button */
:deep(.video-js .vjs-quality-selector .vjs-menu-button-popup .vjs-menu) {
    background-color: oklch(21.87% 0.039 183.18);
    border: 1px solid oklch(38.07% 0.068 181.7);
    border-radius: 0.375rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}

:deep(.video-js .vjs-quality-selector .vjs-menu-item) {
    color: oklch(89.5% 0.159 181.34);
}
:deep(.video-js .vjs-quality-selector .vjs-menu-item:hover) {
    background-color: oklch(38.07% 0.068 181.7);
}

:deep(.video-js .vjs-quality-selector .vjs-menu-item.vjs-selected) {
    background-color: oklch(45.51% 0.081 181.98);
    color: white;
}

/* Playback rate menu */
:deep(.video-js .vjs-playback-rate .vjs-menu) {
    background-color: oklch(21.87% 0.039 183.18);
    border: 1px solid oklch(38.07% 0.068 181.7);
    border-radius: 0.375rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}

:deep(.video-js .vjs-playback-rate .vjs-menu-item) {
    color: oklch(89.5% 0.159 181.34);
}
:deep(.video-js .vjs-playback-rate .vjs-menu-item:hover) {
    background-color: oklch(38.07% 0.068 181.7);
}

:deep(.video-js .vjs-playback-rate .vjs-menu-item.vjs-selected) {
    background-color: oklch(45.51% 0.081 181.98);
    color: white;
}

/* Big play button */
:deep(.video-js .vjs-big-play-button) {
    background-color: oklch(45.51% 0.081 181.98);
    border-radius: 9999px;
    transition: background-color 0.15s;
}
:deep(.video-js .vjs-big-play-button:hover) {
    background-color: oklch(53.86% 0.096 181.61);
}

/* Progress bar */
:deep(.video-js .vjs-progress-holder .vjs-play-progress) {
    background-color: oklch(53.86% 0.096 181.61);
}

:deep(.video-js .vjs-progress-holder .vjs-play-progress:before) {
    display: none;
}

/* Volume bar */
:deep(.video-js .vjs-volume-bar .vjs-volume-level) {
    background-color: oklch(53.86% 0.096 181.61);
}

/* Loading spinner */
:deep(.video-js .vjs-loading-spinner) {
    border-color: oklch(62.48% 0.111 181.51);
}

/* Error display */
:deep(.video-js .vjs-error-display) {
    background-color: rgba(0, 0, 0, 0.9);
}

:deep(.video-js .vjs-error-display .vjs-modal-dialog-content) {
    color: white;
}

/* Tooltips */
:deep(.video-js .vjs-time-tooltip) {
    background-color: oklch(29.53% 0.053 180.86);
    color: oklch(89.5% 0.159 181.34);
}

/* Menu button text */
:deep(.video-js .vjs-quality-selector .vjs-menu-button .vjs-menu-button-popup .vjs-menu .vjs-menu-content) {
    font-size: 0.875rem;
    line-height: 1.25rem;
}

/* Quality selector button - remove default content */
:deep(.video-js .vjs-quality-selector .vjs-icon-placeholder:before) {
    content: none !important;
}

/* Quality selector button text styling */
:deep(.video-js .vjs-quality-selector .vjs-icon-placeholder) {
    font-family: inherit;
    font-weight: normal;
    line-height: inherit;
    font-size: 0.9em;
    display: block;
    min-width: 3em;
    text-align: center;
}

/* Ensure the quality button has proper spacing */
:deep(.video-js .vjs-quality-selector) {
    min-width: 4em;
}

/* Remove any extra text decorations */
:deep(.video-js .vjs-quality-selector .vjs-menu-button-label) {
    display: none;
}

/* Chromecast button styling */
:deep(.video-js .vjs-chromecast-button) {
    display: flex !important;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

:deep(.video-js .vjs-chromecast-button .vjs-icon-placeholder) {
    display: flex;
    align-items: center;
    justify-content: center;
}

:deep(.video-js .vjs-chromecast-button .vjs-icon-placeholder:before) {
    font-size: 1.8em;
}

:deep(.video-js .vjs-chromecast-button:hover) {
    color: oklch(71.68% 0.127 181.62);
}

/* Chromecast connected state */
:deep(.video-js .vjs-chromecast-button.vjs-chromecast-casting) {
    color: oklch(53.86% 0.096 181.61);
}

/* Chromecast menu */
:deep(.video-js .vjs-chromecast-button .vjs-menu) {
    background-color: oklch(21.87% 0.039 183.18);
    border: 1px solid oklch(38.07% 0.068 181.7);
}

:deep(.video-js .vjs-chromecast-button .vjs-menu-item) {
    color: oklch(80.03% 0.142 181.59);
}
:deep(.video-js .vjs-chromecast-button .vjs-menu-item:hover) {
    background-color: oklch(45.51% 0.081 181.98);
}
</style>