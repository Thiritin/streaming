<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

import 'vidstack/player';
import 'vidstack/player/ui';
import 'vidstack/player/layouts/default';
import 'vidstack/player/styles/default/theme.css';
import 'vidstack/player/styles/default/layouts/video.css';

import { isHLSProvider } from 'vidstack';

const props = defineProps({
    src: {
        type: String,
        required: true,
    },
    isLive: {
        type: Boolean,
        default: true,
    },
    /**
     * Set to 'live:dvr' when the playlist exposes a seekable window, otherwise
     * seeking is disabled during live playback. Ignored when isLive is false.
     */
    liveStreamType: {
        type: String,
        default: 'live',
    },
    title: {
        type: String,
        default: '',
    },
    poster: {
        type: String,
        default: '',
    },
    autoplay: {
        type: Boolean,
        default: true,
    },
    /**
     * Google Cast receiver app id. Null uses the default media receiver.
     */
    castReceiverId: {
        type: String,
        default: null,
    },
    /**
     * Key prefix for persisted volume/muted/quality. Shared across pages on
     * purpose so a viewer's volume carries between live and recordings.
     */
    storageKey: {
        type: String,
        default: 'ef-player',
    },
});

const emit = defineEmits([
    'playing',
    'pause',
    'ended',
    'error',
    'autoplay-blocked',
    'quality-change',
    'can-play',
    'toggle-stats',
]);

const player = ref(null);

const streamType = computed(() => (props.isLive ? props.liveStreamType : 'on-demand'));

/**
 * hls.js is a direct dependency, so load it from the bundle. Vidstack otherwise
 * pulls it from jsdelivr at runtime, which breaks on locked-down venue networks.
 */
const onProviderChange = (event) => {
    const provider = event.detail;
    if (!isHLSProvider(provider)) return;

    provider.library = () => import('hls.js');
    provider.config = {
        lowLatencyMode: false,
        backBufferLength: 30,
        maxBufferLength: 30,
        maxMaxBufferLength: 60,
        liveSyncDurationCount: 3,
        abrEwmaDefaultEstimate: 4_000_000,
        manifestLoadingMaxRetry: 6,
        levelLoadingMaxRetry: 6,
        fragLoadingMaxRetry: 6,
    };
};

/**
 * Browsers block unmuted autoplay without a prior user gesture. Retry muted and
 * tell the parent so it can offer an unmute affordance.
 */
const onAutoplayFail = () => {
    const el = player.value;
    if (!el) return;

    el.muted = true;
    el.play().then(() => emit('autoplay-blocked')).catch(() => {});
};

const onQualityChange = (event) => emit('quality-change', event.detail);
const onError = (event) => emit('error', event.detail);

onMounted(() => {
    const el = player.value;
    if (!el) return;

    // Object-valued config has to be assigned as a property. Binding it in the
    // template would stringify it onto the attribute.
    el.googleCast = {
        autoJoinPolicy: 'origin_scoped',
        ...(props.castReceiverId ? { receiverApplicationId: props.castReceiverId } : {}),
    };

    // Extends the built-in shortcuts rather than replacing them. Play/pause,
    // seek, volume, mute, fullscreen and captions already ship with the player.
    el.keyShortcuts = {
        toggleStats: {
            keys: 'i',
            onKeyUp: () => emit('toggle-stats'),
        },
    };
});

onBeforeUnmount(() => {
    player.value?.destroy?.();
});

defineExpose({
    play: () => player.value?.play(),
    pause: () => player.value?.pause(),
    seekToLive: () => player.value?.seekToLiveEdge(),
    requestGoogleCast: () => player.value?.requestGoogleCast(),
    requestAirPlay: () => player.value?.requestAirPlay(),
    getPlayer: () => player.value,
});
</script>

<template>
    <div class="ef-player">
        <media-player
            ref="player"
            :src="src"
            :title="title"
            :stream-type="streamType"
            :autoplay="autoplay"
            :storage="storageKey"
            view-type="video"
            load="eager"
            playsinline
            :prefer-native-hls="false"
            @provider-change="onProviderChange"
            @auto-play-fail="onAutoplayFail"
            @quality-change="onQualityChange"
            @error="onError"
            @playing="emit('playing')"
            @pause="emit('pause')"
            @ended="emit('ended')"
            @can-play="emit('can-play')"
        >
            <media-provider>
                <media-poster v-if="poster" class="vds-poster" :src="poster" :alt="title" />
            </media-provider>

            <media-video-layout />
        </media-player>
    </div>
</template>

<style>
/*
 * Vidstack renders the layout into light DOM after mount, so scoped styles do
 * not reach it. Confine overrides with the .ef-player wrapper instead.
 */
.ef-player,
.ef-player media-player {
    width: 100%;
    height: 100%;
}

.ef-player .vds-video-layout {
    --video-brand: var(--color-primary-400);
    --video-controls-color: var(--fg-1);
    --video-focus-ring-color: var(--color-primary-300);
    --video-bg: var(--surface-0);
    --video-border: 1px solid var(--hairline);
    --video-font-family: inherit;

    --media-brand: var(--color-primary-400);
    --media-controls-color: var(--fg-1);
    --media-font-family: inherit;
    --media-focus-ring-color: var(--color-primary-300);

    /* Buttons */
    --media-button-hover-bg: color-mix(in oklch, var(--color-primary-500) 22%, transparent);
    --media-button-touch-hover-bg: color-mix(in oklch, var(--color-primary-500) 28%, transparent);

    /* Scrubber + volume. Slightly taller than stock so the fill colour reads. */
    --media-slider-track-height: 5px;
    --media-slider-focused-track-height: 8px;
    --media-slider-track-bg: color-mix(in oklch, var(--fg-1) 26%, transparent);
    --media-slider-track-progress-bg: color-mix(in oklch, var(--fg-1) 44%, transparent);
    --media-slider-track-fill-bg: var(--color-primary-400);
    --media-slider-track-fill-live-bg: var(--state-live);
    --media-slider-thumb-bg: var(--color-primary-200);
    --media-slider-thumb-border: 1px solid var(--color-primary-500);
    --media-slider-focused-thumb-shadow: 0 0 0 4px color-mix(in oklch, var(--color-primary-400) 35%, transparent);

    /* Scrub preview + chapter bubbles */
    --media-slider-preview-bg: var(--surface-1);
    --media-slider-preview-border-radius: 6px;
    --media-slider-value-bg: var(--surface-1);
    --media-slider-value-color: var(--fg-1);
    --media-slider-value-border: 1px solid var(--hairline);
    --media-slider-chapter-title-color: var(--fg-2);
    --video-slider-thumbnail-border: 1px solid var(--hairline);

    /* Timestamps */
    --media-time-color: var(--fg-1);
    --media-time-divider-color: var(--fg-3);
    --media-time-font-weight: 500;

    /* Tooltips */
    --media-tooltip-bg-color: var(--surface-1);
    --media-tooltip-color: var(--fg-1);
    --media-tooltip-border: 1px solid var(--hairline);
    --media-tooltip-border-radius: 6px;

    /* Settings / quality menus */
    --media-menu-bg: var(--surface-1);
    --media-menu-border: 1px solid var(--hairline);
    --media-menu-border-radius: 8px;
    --media-menu-divider: 1px solid var(--hairline);
    --media-menu-item-hover-bg: var(--surface-3);
    --media-menu-section-bg: var(--surface-2);
    --media-menu-text-color: var(--fg-1);
    --media-menu-text-secondary-color: var(--fg-2);
    --media-menu-hint-color: var(--fg-2);
    --media-menu-radio-icon-color: var(--color-primary-300);
    --media-menu-checkbox-bg-active: var(--color-primary-500);
    --media-menu-slider-track-fill-bg: var(--color-primary-400);
    --media-menu-scrollbar-thumb-bg: var(--surface-3);
    --media-menu-top-bar-bg: var(--surface-2);

    /* Keyboard action bezel + buffering spinner */
    --media-kb-bezel-bg: color-mix(in oklch, var(--surface-0) 70%, transparent);
    --media-kb-icon-color: var(--fg-1);
    --media-buffering-track-fill-color: var(--color-primary-400);
    --media-buffering-track-color: color-mix(in oklch, var(--fg-1) 22%, transparent);

    --media-poster-bg: var(--surface-0);
}

/* No matching CSS var for the control-bar scrim, so target the class. */
.ef-player .vds-video-layout .vds-controls {
    background: linear-gradient(to top, color-mix(in oklch, var(--surface-0) 92%, transparent), transparent 90%);
}

/* Live badge reads as live rather than as a neutral chip when at the edge. */
.ef-player .vds-live-button[data-edge] {
    color: var(--state-live);
}
</style>
