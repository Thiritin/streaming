<template>
  <div class="live-preview group" :class="`live-preview-${variant}`">
    <div ref="frame" class="relative aspect-video overflow-hidden bg-primary-950">
      <video
        v-show="streaming"
        ref="player"
        class="absolute inset-0 w-full h-full object-cover"
        :poster="show.thumbnail_url || undefined"
        muted
        playsinline
        autoplay
        @playing="onPlaying"
        @waiting="onWaiting"
      />

      <div v-if="!streaming" class="absolute inset-0">
        <img
          v-if="show.thumbnail_url"
          :src="show.thumbnail_url"
          :alt="show.title"
          :loading="priority ? 'eager' : 'lazy'"
          :fetchpriority="priority ? 'high' : 'auto'"
          decoding="async"
          class="w-full h-full object-cover transition-[opacity,filter] duration-(--dur-slow) ease-(--ease-out-expo)"
          :class="posterLoaded ? 'opacity-100 blur-0' : 'opacity-0 blur-md'"
          @load="posterLoaded = true"
        />
        <TilePlaceholder v-else :label="show.source" />

        <div class="absolute inset-0 flex items-center justify-center bg-primary-950/55">
          <div class="px-4 text-center">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-200">{{ playbackLabel }}</p>
            <p v-if="!isLive" class="mt-1 text-sm text-primary-300">{{ startsLabel }}</p>
          </div>
        </div>
      </div>

      <div class="absolute top-3 left-3 z-10 flex items-center gap-2">
        <span v-if="isLive && show.viewer_count" class="preview-badge">
          {{ formatViewerCount(show.viewer_count) }} watching
        </span>
      </div>

      <div v-if="isLive && show.started_at" class="absolute top-3 right-3 z-10">
        <span class="preview-badge tabular-nums">{{ liveDuration }}</span>
      </div>

      <!-- The scrim is what makes the copy readable over an arbitrary frame of
           video: a dark ramp from the bottom edge, not a flat tint over the picture. -->
      <div class="preview-scrim" aria-hidden="true" />

      <div class="preview-copy">
        <p class="preview-eyebrow">
          <span v-if="isLive" class="live-pip" aria-hidden="true" />
          <span class="truncate">{{ channelLabel }}</span>
        </p>

        <h3 v-if="withText" class="preview-title">{{ show.title }}</h3>

        <p v-if="withText && show.description" class="preview-description">{{ show.description }}</p>

        <!-- Sits under the stretched link on purpose: it reads as the affordance,
             but the whole frame is what actually navigates, so there is no nested
             link and no dead zone around the button. -->
        <span class="watch-now">
          Watch now
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
          </svg>
        </span>
      </div>

      <button
        v-if="withMute && streaming"
        type="button"
        class="preview-control"
        :aria-pressed="!muted"
        @click.stop.prevent="toggleMuted"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 5 6 9H3v6h3l5 4V5z" />
          <path v-if="muted" stroke-linecap="round" d="m16 9 5 6m0-6-5 6" />
          <path v-else stroke-linecap="round" d="M16 9a4 4 0 0 1 0 6m3-9a8 8 0 0 1 0 12" />
        </svg>
        {{ muted ? 'Unmute' : 'Mute' }}
      </button>

      <Link
        :href="route('show.view', show.slug)"
        class="preview-link"
        prefetch
        @pointerdown="claimHero"
        @keydown.enter="claimHero"
      >
        <span class="sr-only">Watch {{ show.title }}</span>
      </Link>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import Hls from 'hls.js';
import TilePlaceholder from '../TilePlaceholder.vue';
import { useNow } from '@/composables/useNow';
import { claimMediaHero } from '@/composables/useMediaHero';

const props = defineProps({
  show: { type: Object, required: true },
  /** `featured` is the big one; `compact` is a side entry point. */
  variant: { type: String, default: 'featured' },
  /** Title and abstract in the overlay. Off when the page prints them beside it. */
  withText: { type: Boolean, default: false },
  withMute: { type: Boolean, default: false },
  /*
   * Pin playback to the bottom rung of the ladder. The side previews are a couple
   * of hundred pixels wide and exist to say what is on, so pulling 6 Mbps for them
   * spends an edge's uplink on pixels nobody can see.
   */
  lowQuality: { type: Boolean, default: false },
  priority: { type: Boolean, default: false },
  /*
   * Status of the channel behind the show, when the page tracks it. A show can be
   * live while its source is not publishing, and that is not "connecting".
   */
  sourceStatus: { type: String, default: null },
});

const now = useNow();
const player = ref(null);
const frame = ref(null);
const streaming = ref(false);
const failed = ref(false);
const muted = ref(true);
const posterLoaded = ref(false);
let hlsInstance = null;
let recoveries = 0;
const MAX_RECOVERIES = 3;

const isLive = computed(() => props.show.status === 'live');

const sourceIsDown = computed(() => props.sourceStatus !== null && props.sourceStatus !== 'online');

const playbackLabel = computed(() => {
  if (!isLive.value) return 'Off air';
  if (sourceIsDown.value) return props.sourceStatus === 'error' ? 'Connection interrupted' : 'Stream offline';

  return failed.value ? 'Stream unavailable' : 'Connecting';
});

// The line the request called "EF PRIME LIVE": which channel this is, and whether
// it is on. The channel name comes from the source, so nothing is hardcoded.
const channelLabel = computed(() => {
  const channel = props.show.source || 'Stream';

  if (isLive.value) return `${channel} live`;
  if (props.show.status === 'starting_soon') return `${channel} starting soon`;

  return `${channel} off air`;
});

const liveDuration = computed(() => {
  if (!props.show.started_at) return null;
  const diff = Math.max(0, Math.floor((now.value - new Date(props.show.started_at)) / 1000));
  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);
  const seconds = diff % 60;
  const pad = (n) => n.toString().padStart(2, '0');

  return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`;
});

const startsLabel = computed(() => {
  if (!props.show.scheduled_start) return 'Nothing scheduled';
  const start = new Date(props.show.scheduled_start);
  const diff = Math.floor((start - now.value) / 1000);

  if (diff <= 0) return 'Starting shortly';
  if (diff < 3600) return `in ${Math.ceil(diff / 60)}m`;
  if (diff < 86400) return `today at ${formatClock(props.show.scheduled_start)}`;

  return `${start.toLocaleDateString([], { weekday: 'short' })} at ${formatClock(props.show.scheduled_start)}`;
});

const formatClock = (value) => new Date(value).toLocaleTimeString([], {
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

const formatViewerCount = (count) => {
  if (count >= 1000000) return `${(count / 1000000).toFixed(1)}M`;
  if (count >= 1000) return `${(count / 1000).toFixed(1)}K`;
  return count?.toString() ?? '0';
};

const claimHero = () => claimMediaHero(frame.value);

// Cached: matchMedia is not guaranteed to hand back the same object for the same
// query, and removeEventListener on a fresh one removes nothing.
let sideSlot = null;

const sideSlotQuery = () => {
  if (typeof window === 'undefined') return null;
  sideSlot ??= window.matchMedia('(min-width: 1024px)');

  return sideSlot;
};

const wideEnough = () => !props.lowQuality || (sideSlotQuery()?.matches ?? true);

const onViewportChange = () => {
  stopPlayback();
  startPlayback();
};

const onPlaying = () => {
  streaming.value = true;
  failed.value = false;
};

// A stall is not a failure: HLS drops a segment now and then and recovers.
const onWaiting = () => {
  if (!hlsInstance) streaming.value = false;
};

const toggleMuted = () => {
  if (!player.value) return;
  muted.value = !muted.value;
  player.value.muted = muted.value;

  if (!muted.value) {
    player.value.play().catch(() => {
      // Some browsers refuse unmuted playback without a gesture; fall back to muted.
      muted.value = true;
      player.value.muted = true;
    });
  }
};

const stopPlayback = ({ errored = false } = {}) => {
  if (errored) failed.value = true;

  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }
  if (player.value) {
    player.value.pause();
    player.value.removeAttribute('src');
  }
  streaming.value = false;
};

// Autoplay is muted on purpose: browsers block audible autoplay, and a front page
// that shouts at you is worse than one you have to unmute.
const startPlayback = async () => {
  if (!props.show.hls_url || !isLive.value || sourceIsDown.value) return;
  // Below lg the side previews stack full width under the featured one, so three
  // streams would be pulling at once on the connection least able to afford it.
  if (!wideEnough()) return;

  await nextTick();
  const el = player.value;
  if (!el) return;

  el.muted = true;
  muted.value = true;
  failed.value = false;
  recoveries = 0;

  const fail = () => stopPlayback({ errored: true });

  if (Hls.isSupported()) {
    hlsInstance = new Hls({
      enableWorker: true,
      lowLatencyMode: false,
      maxBufferLength: props.lowQuality ? 8 : 12,
      backBufferLength: props.lowQuality ? 10 : 30,
      startLevel: props.lowQuality ? 0 : -1,
    });
    hlsInstance.loadSource(props.show.hls_url);
    hlsInstance.attachMedia(el);
    hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
      // Levels are ordered by bitrate, so 0 is the sd rung. Setting currentLevel
      // rather than startLevel is what keeps ABR from climbing back up.
      if (props.lowQuality) hlsInstance.currentLevel = 0;
      el.play().catch(fail);
    });
    // Only fatal errors count. A 404 on one segment or a playlist refresh blip is
    // routine on a live edge, and hls.js already retries those itself.
    hlsInstance.on(Hls.Events.ERROR, (event, data) => {
      if (!data.fatal) return;

      if (data.type === Hls.ErrorTypes.NETWORK_ERROR && recoveries < MAX_RECOVERIES) {
        recoveries += 1;
        hlsInstance.startLoad();

        return;
      }

      if (data.type === Hls.ErrorTypes.MEDIA_ERROR && recoveries < MAX_RECOVERIES) {
        recoveries += 1;
        hlsInstance.recoverMediaError();

        return;
      }

      fail();
    });
  } else if (el.canPlayType('application/vnd.apple.mpegurl')) {
    el.src = props.show.hls_url;
    el.play().catch(fail);
  }
};

watch(() => [props.show.id, props.show.hls_url, props.show.status, props.sourceStatus], () => {
  stopPlayback();
  startPlayback();
});

watch(() => props.show.thumbnail_url, () => posterLoaded.value = false);

onMounted(() => {
  startPlayback();
  if (props.lowQuality) sideSlotQuery()?.addEventListener('change', onViewportChange);
});

onUnmounted(() => {
  if (props.lowQuality) sideSlotQuery()?.removeEventListener('change', onViewportChange);
  stopPlayback();
});
</script>

<style scoped>
@reference "../../../css/app.css";

.live-preview {
  @apply relative overflow-hidden rounded-xl ring-1 ring-white/10 transition-shadow duration-(--dur-base);
}

.live-preview-featured {
  @apply shadow-2xl shadow-primary-950/50;
}

.live-preview:hover {
  @apply ring-primary-500/60;
}

.live-preview:has(.preview-link:focus-visible) {
  @apply ring-2 ring-primary-300;
}

/* Covers the frame, so the picture is what gets clicked. The mute button sits
   above it; everything in the overlay sits below and inherits the same target. */
.preview-link {
  @apply absolute inset-0 z-20;
}

.preview-scrim {
  @apply absolute inset-x-0 bottom-0 z-0 h-3/5;
  background: linear-gradient(to top, rgb(0 0 0 / 0.88) 0%, rgb(0 0 0 / 0.55) 38%, transparent 100%);
}

.preview-copy {
  @apply absolute inset-x-0 bottom-0 z-10 flex flex-col items-start gap-1.5 p-3 sm:p-4;
}

.preview-eyebrow {
  @apply flex max-w-full items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.16em] text-white/85;
}

.preview-title {
  @apply text-white font-bold leading-tight text-balance;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.live-preview-featured .preview-title {
  @apply text-xl sm:text-2xl;
}

.live-preview-compact .preview-title {
  @apply text-sm;
}

.preview-description {
  @apply text-xs sm:text-sm leading-relaxed text-white/75;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.live-preview-compact .preview-description {
  display: none;
}

.watch-now {
  @apply mt-1 inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-primary-950 transition-colors;
}

.live-preview-featured .watch-now {
  @apply px-4 py-2 text-sm;
}

.live-preview:hover .watch-now {
  @apply bg-primary-400 text-white;
}

.preview-control {
  @apply absolute bottom-3 right-3 z-30 inline-flex items-center gap-2 rounded-md bg-black/65 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-black/85;
}

.preview-control:focus-visible {
  @apply outline-none ring-2 ring-primary-300;
}

.live-pip {
  @apply w-1.5 h-1.5 shrink-0 rounded-full bg-red-500;
  animation: blink 1.8s ease-in-out infinite;
}

.preview-badge {
  @apply inline-flex items-center gap-1.5 rounded bg-black/70 px-2 py-1 text-[11px] font-medium text-white;
}

@media (prefers-reduced-motion: reduce) {
  .live-pip {
    animation: none;
  }
}
</style>
