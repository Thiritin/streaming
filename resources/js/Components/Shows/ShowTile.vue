<template>
  <div
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave"
    class="show-tile-wrapper media-tile"
  >
    <!-- `prefetch` is what makes the view transition worth having: the response is
         usually already cached by the time the click lands, so the morph starts
         immediately instead of after a round trip spent frozen. -->
    <Link
      :href="route('show.view', show.slug)"
      class="group block"
      prefetch
      @pointerdown="claimHero"
      @keydown.enter="claimHero"
    >
      <!-- Thumbnail Container. Also the origin of the shared-element morph into
           the player, which is why it carries the ref. -->
      <div
        ref="thumbnail"
        class="relative aspect-video rounded-xl overflow-hidden bg-primary-800 ring-1 ring-white/5 group-hover:ring-2 group-hover:ring-primary-500/60 group-hover:shadow-lg group-hover:shadow-primary-500/20 transition-all duration-(--dur-base)"
      >
        <!-- Video Preview (only for live shows) -->
        <Transition
          enter-active-class="transition-all duration-(--dur-slower) ease-(--ease-out-expo)"
          enter-from-class="opacity-0 blur-xl scale-105"
          enter-to-class="opacity-100 blur-0 scale-100"
          leave-active-class="transition-all duration-(--dur-base) ease-(--ease-in-quart)"
          leave-from-class="opacity-100 blur-0 scale-100"
          leave-to-class="opacity-0 blur-md scale-105"
        >
          <video
            v-if="showVideoPreview && isLive && streamUrl"
            ref="videoPreview"
            class="w-full h-full object-cover absolute inset-0 z-10"
            muted
            playsinline
            @error="handleVideoError"
          />
        </Transition>

        <!-- Thumbnail. The wrapper animates the swap to the live preview; the
             <img> owns its own fade, driven by `load`, so a still that arrives
             late eases in instead of popping into a settled grid. -->
        <Transition
          enter-active-class="transition-opacity duration-(--dur-slow) ease-(--ease-out-expo)"
          enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-(--dur-slow) ease-(--ease-in-quart)"
          leave-to-class="opacity-0"
        >
          <div v-if="currentThumbnail && !showVideoPreview" class="absolute inset-0">
            <img
              :src="currentThumbnail"
              :alt="show.title"
              :loading="priority ? 'eager' : 'lazy'"
              :fetchpriority="priority ? 'high' : 'auto'"
              decoding="async"
              class="w-full h-full object-cover transition-[opacity,filter,transform] duration-(--dur-slow) ease-(--ease-out-expo) group-hover:scale-105"
              :class="thumbnailLoaded ? 'opacity-100 blur-0' : 'opacity-0 blur-md'"
              @load="thumbnailLoaded = true"
              @error="handleImageError"
            />
          </div>
        </Transition>

        <!-- Placeholder when no thumbnail -->
        <TilePlaceholder
          v-if="!currentThumbnail && !showVideoPreview"
          :label="show.source"
        />

        <!-- Loading art for a lazy thumbnail that has not decoded yet, so a grid
             scrolled into view sweeps instead of showing flat boxes. -->
        <div
          v-else-if="currentThumbnail && !thumbnailLoaded && !showVideoPreview"
          class="media-skeleton"
          aria-hidden="true"
        />

        <!-- Top badges row -->
        <div class="absolute top-2 left-2 z-20">
          <!-- Live Badge -->
          <span v-if="isLive" class="live-badge">
            LIVE
          </span>

          <!-- Starting Soon Badge -->
          <span v-else-if="isStartingSoon" class="starting-soon-badge">
            STARTING SOON
          </span>

          <!-- Upcoming Time -->
          <span v-else-if="isUpcoming" class="time-badge">
            {{ timeUntilStart }}
          </span>
        </div>

        <!-- Bottom left: Viewer count -->
        <div v-if="isLive && show.viewer_count" class="absolute bottom-2 left-2 z-20">
          <span class="viewer-badge">
            <FaUsersIcon class="w-3 h-3" />
            {{ formatViewerCount(show.viewer_count) }}
          </span>
        </div>

        <!-- Bottom right: Duration -->
        <div v-if="isLive && show.started_at" class="absolute bottom-2 right-2 z-20">
          <span class="duration-badge">
            {{ liveDuration }}
          </span>
        </div>

        <!-- Hover Overlay with play icon -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-(--dur-base) z-10" />
        <div class="absolute inset-0 flex items-center justify-center z-10">
          <div class="w-14 h-14 rounded-full bg-primary-500/90 flex items-center justify-center opacity-0 group-hover:opacity-100 transform scale-50 group-hover:scale-100 transition-all duration-(--dur-base) ease-(--ease-spring) shadow-lg shadow-primary-500/30">
            <FaPlayIcon class="w-6 h-6 text-white ml-0.5" />
          </div>
        </div>
      </div>

      <!-- Content: title plus a single meta row keeps tiles short so more fit above the fold -->
      <div class="mt-2.5">
        <h3 class="font-semibold text-white text-sm leading-tight line-clamp-2 group-hover:text-primary-300 transition-colors">
          {{ show.title }}
        </h3>

        <p class="mt-1 flex items-center gap-2 text-xs text-primary-400 min-w-0">
          <span v-if="show.source" class="truncate">{{ show.source }}</span>
          <span v-if="show.source && metaTime" class="text-primary-600" aria-hidden="true">·</span>
          <span v-if="metaTime" class="tabular-nums whitespace-nowrap">{{ metaTime }}</span>
        </p>
      </div>
    </Link>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { ref, computed, onUnmounted, nextTick, watch, Transition } from 'vue';
import TilePlaceholder from '../TilePlaceholder.vue';
import FaPlayIcon from '../Icons/FaPlayIcon.vue';
import FaUsersIcon from '../Icons/FaUsersIcon.vue';
import Hls from 'hls.js';
import { useNow } from '@/composables/useNow';
import { claimMediaHero } from '@/composables/useMediaHero';

// Props
const props = defineProps({
  show: {
    type: Object,
    required: true,
  },
  // Set on the tiles the first screen shows. Everything below the fold loads
  // lazily, so a 40-tile grid does not open 40 image requests on navigation.
  priority: {
    type: Boolean,
    default: false,
  }
});

// Reactive state
const currentThumbnail = ref(props.show.thumbnail_url);
const thumbnailLoaded = ref(false);
const thumbnail = ref(null);
const showVideoPreview = ref(false);
const videoPreview = ref(null);
let hoverTimeout = null;
let hlsInstance = null;

const now = useNow();

// Computed properties
const isLive = computed(() => props.show.status === 'live');
const isStartingSoon = computed(() => props.show.status === 'starting_soon');
const isUpcoming = computed(() => props.show.status === 'scheduled');

const liveDuration = computed(() => formatDuration(props.show.started_at, now.value));
const timeUntilStart = computed(() => formatTimeUntil(props.show.scheduled_start, now.value));

// The meta row shows how long a live show has been running, or when a scheduled one starts.
const metaTime = computed(() => {
  if (isLive.value && props.show.started_at) {
    return `live ${liveDuration.value}`;
  }
  if ((isUpcoming.value || isStartingSoon.value) && props.show.scheduled_start) {
    return formatScheduledTime(props.show.scheduled_start);
  }
  return null;
});

// Get the stream URL for preview
const streamUrl = computed(() => {
  if (!props.show.hls_url) return null;
  return props.show.hls_url;
});

// Methods
// Both activation paths, because Enter on a focused link fires `click` without
// ever firing `pointerdown`: without the keydown the old page would be captured
// with nothing named, and keyboard users would lose the morph.
const claimHero = () => claimMediaHero(thumbnail.value);

const handleImageError = () => {
  currentThumbnail.value = null;
  thumbnailLoaded.value = false;
};

const handleVideoError = () => {
  showVideoPreview.value = false;
};

const handleMouseEnter = () => {
  if (!isLive.value || !streamUrl.value) return;

  hoverTimeout = setTimeout(() => {
    showVideoPreview.value = true;
    nextTick(() => {
      if (videoPreview.value && streamUrl.value) {
        if (Hls.isSupported()) {
          hlsInstance = new Hls({
            enableWorker: true,
            lowLatencyMode: false,
            backBufferLength: 60,
            maxBufferSize: 30 * 1000 * 1000,
            maxBufferLength: 10,
            startLevel: 0,
          });

          hlsInstance.loadSource(streamUrl.value);
          hlsInstance.attachMedia(videoPreview.value);

          hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
            videoPreview.value.play().catch((error) => {
              console.log('Video autoplay failed:', error);
              showVideoPreview.value = false;
            });
          });

          hlsInstance.on(Hls.Events.ERROR, (event, data) => {
            if (data.fatal) {
              console.log('HLS fatal error:', data);
              showVideoPreview.value = false;
            }
          });
        } else if (videoPreview.value.canPlayType('application/vnd.apple.mpegurl')) {
          videoPreview.value.src = streamUrl.value;
          videoPreview.value.play().catch((error) => {
            console.log('Video autoplay failed:', error);
            showVideoPreview.value = false;
          });
        }
      }
    });
  }, 300);
};

const handleMouseLeave = () => {
  if (hoverTimeout) {
    clearTimeout(hoverTimeout);
    hoverTimeout = null;
  }

  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }

  if (videoPreview.value) {
    videoPreview.value.pause();
    videoPreview.value.src = '';
  }
  showVideoPreview.value = false;
};

const formatViewerCount = (count) => {
  if (count >= 1000000) {
    return (count / 1000000).toFixed(1) + 'M';
  }
  if (count >= 1000) {
    return (count / 1000).toFixed(1) + 'K';
  }
  return count?.toString() || '0';
};

const formatDuration = (startTime, reference = Date.now()) => {
  const start = new Date(startTime);
  const diff = Math.floor((reference - start) / 1000);

  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);
  const seconds = diff % 60;

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
};

const formatTimeUntil = (scheduledTime, reference = Date.now()) => {
  const scheduled = new Date(scheduledTime);
  const diff = Math.floor((scheduled - reference) / 1000);

  if (diff <= 0) {
    return 'Starting soon';
  }

  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);

  if (hours > 24) {
    const days = Math.floor(hours / 24);
    return `in ${days}d`;
  }

  if (hours > 0) {
    return `in ${hours}h ${minutes}m`;
  }

  return `in ${minutes}m`;
};

const formatScheduledTime = (scheduledTime) => {
  const date = new Date(scheduledTime);
  const today = new Date();
  const tomorrow = new Date(today);
  tomorrow.setDate(tomorrow.getDate() + 1);

  const timeStr = date.toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  });

  if (date.toDateString() === today.toDateString()) {
    return `Today at ${timeStr}`;
  } else if (date.toDateString() === tomorrow.toDateString()) {
    return `Tomorrow at ${timeStr}`;
  } else {
    return date.toLocaleDateString('en-US', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    });
  }
};

// Lifecycle
// No per-tile Echo subscription: `thumbnail.updated` also broadcasts on the page's
// `shows` channel, and one socket subscription per tile meant a grid of 20 shows
// opened 20 of them. The page updates the prop; this keeps the local copy in step.
watch(() => props.show.thumbnail_url, (url) => {
  currentThumbnail.value = url;
  // A refreshed still is a new request, so hand it back its fade rather than
  // swapping pixels under a thumbnail that is already at full opacity.
  thumbnailLoaded.value = false;
});

onUnmounted(() => {
  if (hoverTimeout) {
    clearTimeout(hoverTimeout);
  }
  if (hlsInstance) {
    hlsInstance.destroy();
  }
});
</script>

<style>
@reference "../../../css/app.css";

.live-badge {
  @apply bg-red-600 text-white px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide;
}

.starting-soon-badge {
  @apply bg-orange-500 text-white px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide;
  animation: pulse 2s ease-in-out infinite;
}

.time-badge {
  @apply bg-black/70 text-white px-2 py-0.5 rounded text-[10px] font-medium;
}

.viewer-badge {
  @apply inline-flex items-center gap-1 bg-black/70 text-white px-2 py-0.5 rounded text-[10px] font-medium;
}

.duration-badge {
  @apply bg-black/80 text-white px-1.5 py-0.5 rounded text-[10px] font-medium tabular-nums;
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
