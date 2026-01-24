<template>
  <div
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave"
    class="show-tile-wrapper"
  >
    <Link
      :href="route('show.view', show.slug)"
      class="group block"
    >
      <!-- Thumbnail Container -->
      <div class="relative aspect-video rounded-xl overflow-hidden bg-primary-800 ring-1 ring-white/5 group-hover:ring-2 group-hover:ring-primary-500/60 group-hover:shadow-lg group-hover:shadow-primary-500/20 transition-all duration-300">
        <!-- Video Preview (only for live shows) -->
        <Transition
          enter-active-class="transition-all duration-700 ease-out"
          enter-from-class="opacity-0 blur-xl scale-105"
          enter-to-class="opacity-100 blur-0 scale-100"
          leave-active-class="transition-all duration-300 ease-in"
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

        <!-- Thumbnail Image -->
        <Transition
          enter-active-class="transition-all duration-500 ease-out"
          enter-from-class="opacity-0 blur-md"
          enter-to-class="opacity-100 blur-0"
          leave-active-class="transition-all duration-500 ease-in"
          leave-from-class="opacity-100 blur-0"
          leave-to-class="opacity-0 blur-lg"
        >
          <img
            v-if="currentThumbnail && !showVideoPreview"
            :src="currentThumbnail"
            :alt="show.title"
            class="w-full h-full object-cover absolute inset-0 transition-transform duration-500 group-hover:scale-105"
            @error="handleImageError"
          />
        </Transition>

        <!-- Placeholder when no thumbnail -->
        <div v-if="!currentThumbnail && !showVideoPreview" class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-700 to-primary-900">
          <FaVideoIcon class="w-16 h-16 text-primary-500" />
        </div>

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
            {{ formatTimeUntil(show.scheduled_start) }}
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
            {{ formatDuration(show.started_at) }}
          </span>
        </div>

        <!-- Hover Overlay with play icon -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10" />
        <div class="absolute inset-0 flex items-center justify-center z-10">
          <div class="w-14 h-14 rounded-full bg-primary-500/90 backdrop-blur-sm flex items-center justify-center opacity-0 group-hover:opacity-100 transform scale-50 group-hover:scale-100 transition-all duration-300 shadow-lg shadow-primary-500/30">
            <FaPlayIcon class="w-6 h-6 text-white ml-0.5" />
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="mt-3">
        <!-- Title -->
        <h3 class="font-semibold text-white text-sm leading-tight line-clamp-2 group-hover:text-primary-300 transition-colors">
          {{ show.title }}
        </h3>

        <!-- Source/Channel -->
        <p v-if="show.source" class="text-primary-400 text-sm mt-0.5 truncate">
          {{ show.source }}
        </p>

        <!-- Scheduled Time for Upcoming or Starting Soon -->
        <p v-if="isUpcoming || isStartingSoon" class="text-primary-500 text-xs mt-1 flex items-center gap-1">
          <FaClockIcon class="w-3 h-3" />
          {{ formatScheduledTime(show.scheduled_start) }}
        </p>
      </div>
    </Link>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, nextTick, Transition } from 'vue';
import FaVideoIcon from '../Icons/FaVideoIcon.vue';
import FaPlayIcon from '../Icons/FaPlayIcon.vue';
import FaClockIcon from '../Icons/FaClockIcon.vue';
import FaUsersIcon from '../Icons/FaUsersIcon.vue';
import Hls from 'hls.js';

// Props
const props = defineProps({
  show: {
    type: Object,
    required: true,
  }
});

// Reactive state
const currentThumbnail = ref(props.show.thumbnail_url);
const showVideoPreview = ref(false);
const videoPreview = ref(null);
let updateInterval = null;
let hoverTimeout = null;
let hlsInstance = null;

// Computed properties
const isLive = computed(() => props.show.status === 'live');
const isStartingSoon = computed(() => props.show.status === 'starting_soon');
const isUpcoming = computed(() => props.show.status === 'scheduled');

// Get the stream URL for preview
const streamUrl = computed(() => {
  if (!props.show.hls_url) return null;
  return props.show.hls_url;
});

// Methods
const handleImageError = () => {
  currentThumbnail.value = null;
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

const formatDuration = (startTime) => {
  const start = new Date(startTime);
  const now = new Date();
  const diff = Math.floor((now - start) / 1000);

  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);
  const seconds = diff % 60;

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
};

const formatTimeUntil = (scheduledTime) => {
  const scheduled = new Date(scheduledTime);
  const now = new Date();
  const diff = Math.floor((scheduled - now) / 1000);

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
onMounted(() => {
  if (isLive.value || isUpcoming.value || isStartingSoon.value) {
    updateInterval = setInterval(() => {
      // Force re-render to update time displays
    }, 1000);
  }

  Echo.channel(`show.${props.show.id}`)
    .listen('.thumbnail.updated', (e) => {
      if (e.thumbnail_url) {
        currentThumbnail.value = e.thumbnail_url;
      }
    });
});

onUnmounted(() => {
  if (updateInterval) {
    clearInterval(updateInterval);
  }
  if (hoverTimeout) {
    clearTimeout(hoverTimeout);
  }
  if (hlsInstance) {
    hlsInstance.destroy();
  }
  Echo.leave(`show.${props.show.id}`);
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
  @apply bg-black/70 backdrop-blur-sm text-white px-2 py-0.5 rounded text-[10px] font-medium;
}

.viewer-badge {
  @apply inline-flex items-center gap-1 bg-black/70 backdrop-blur-sm text-white px-2 py-0.5 rounded text-[10px] font-medium;
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
