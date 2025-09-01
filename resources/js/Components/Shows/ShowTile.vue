<template>
  <div 
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave"
    class="show-tile-wrapper"
  >
    <Link 
      :href="route('show.view', show.slug)"
      class="show-tile group relative block overflow-hidden rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105"
    >
      <!-- Thumbnail Container -->
      <div class="aspect-video relative bg-primary-900 overflow-hidden">
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
            class="w-full h-full object-cover absolute inset-0"
            @error="handleImageError"
          />
        </Transition>
        
        <!-- Placeholder when no thumbnail -->
        <div v-if="!currentThumbnail && !showVideoPreview" class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-800 to-primary-900">
          <FaVideoIcon class="w-20 h-20 text-white" />
        </div>
      
      <!-- Live Badge -->
      <div v-if="isLive" class="absolute top-3 left-3">
        <span class="bg-red-600 text-white px-3 py-1.5 rounded text-sm font-bold uppercase flex items-center">
          <span class="live-dot"></span>
          LIVE
        </span>
      </div>
      
      <!-- Upcoming Time -->
      <div v-else-if="isUpcoming" class="absolute top-3 left-3">
        <span class="bg-black/70 text-white px-3 py-1.5 rounded text-sm">
          {{ formatTimeUntil(show.scheduled_start) }}
        </span>
      </div>
      
      <!-- Duration/Time Overlay -->
      <div class="absolute bottom-2 right-2">
        <span v-if="isLive && show.started_at" class="bg-black/70 text-white px-2 py-1 rounded text-xs">
          {{ formatDuration(show.started_at) }}
        </span>
      </div>
      
      <!-- Hover Overlay -->
      <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-colors duration-300 flex items-center justify-center">
        <FaPlayIcon class="text-white w-16 h-16 opacity-0 group-hover:opacity-100 transition-opacity duration-300" />
      </div>
    </div>
    
    <!-- Content -->
    <div class="p-4 bg-primary-800">
      <!-- Title -->
      <h3 class="font-semibold text-lg text-white truncate group-hover:text-primary-300 transition-colors">
        {{ show.title }}
      </h3>
      
      <!-- Source -->
      <p v-if="show.source" class="text-base text-primary-400 truncate">
        {{ show.source }}
      </p>
      
      <!-- Scheduled Time for Upcoming -->
      <p v-if="isUpcoming" class="text-base text-primary-400 mt-1">
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
import Hls from 'hls.js';

// Props
const props = defineProps({
  show: {
    type: Object,
    required: true,
  }
});

// Reactive state
const currentThumbnail = ref(props.show.thumbnail_url); // Using accessor that returns signed URL
const showVideoPreview = ref(false);
const videoPreview = ref(null);
let updateInterval = null;
let hoverTimeout = null;
let hlsInstance = null;

// Computed properties
const isLive = computed(() => props.show.status === 'live');
const isUpcoming = computed(() => props.show.status === 'scheduled');

// Get the stream URL for preview
const streamUrl = computed(() => {
  if (!props.show.hls_url) return null;
  // Use master playlist which will adapt quality based on bandwidth
  return props.show.hls_url;
});

// Methods
const handleImageError = () => {
  currentThumbnail.value = null;
};

const handleVideoError = () => {
  // If video fails to load, hide the preview
  showVideoPreview.value = false;
};

const handleMouseEnter = () => {
  // Only show video preview for live shows
  if (!isLive.value || !streamUrl.value) return;
  
  // Add a small delay to prevent loading on quick hovers
  hoverTimeout = setTimeout(() => {
    showVideoPreview.value = true;
    // Wait for next tick to ensure video element is rendered
    nextTick(() => {
      if (videoPreview.value && streamUrl.value) {
        // Initialize HLS.js
        if (Hls.isSupported()) {
          hlsInstance = new Hls({
            enableWorker: true,
            lowLatencyMode: false,
            backBufferLength: 60,
            maxBufferSize: 30 * 1000 * 1000, // 30MB
            maxBufferLength: 10, // seconds
            startLevel: 0, // Start with lowest quality for faster loading
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
          // Native HLS support (Safari)
          videoPreview.value.src = streamUrl.value;
          videoPreview.value.play().catch((error) => {
            console.log('Video autoplay failed:', error);
            showVideoPreview.value = false;
          });
        }
      }
    });
  }, 300); // 300ms delay
};

const handleMouseLeave = () => {
  // Clear the hover timeout if mouse leaves before delay
  if (hoverTimeout) {
    clearTimeout(hoverTimeout);
    hoverTimeout = null;
  }
  
  // Clean up HLS instance
  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }
  
  // Stop and hide video preview
  if (videoPreview.value) {
    videoPreview.value.pause();
    videoPreview.value.src = '';
  }
  showVideoPreview.value = false;
};

const formatDuration = (startTime) => {
  const start = new Date(startTime);
  const now = new Date();
  const diff = Math.floor((now - start) / 1000);
  
  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);
  
  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:00`;
  }
  return `${minutes}:${(diff % 60).toString().padStart(2, '0')}`;
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
    return `in ${days} day${days > 1 ? 's' : ''}`;
  }
  
  if (hours > 0) {
    return `in ${hours}h ${minutes}m`;
  }
  
  return `in ${minutes} min`;
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
  // Update duration/countdown every second for live/upcoming shows
  if (isLive.value || isUpcoming.value) {
    updateInterval = setInterval(() => {
      // Force re-render to update time displays
    }, 1000);
  }
  
  // Listen for thumbnail updates via WebSocket
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
.live-dot {
  width: 0.5rem;
  height: 0.5rem;
  background-color: white;
  border-radius: 9999px;
  margin-right: 0.25rem;
  animation: blink 1.5s ease-in-out infinite;
}

.show-tile {
  background-color: rgb(0 59 50); /* primary-700 */
}

.show-tile:hover {
  background-color: rgb(0 80 75); /* primary-600 */
}
</style>