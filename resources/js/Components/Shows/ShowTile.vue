<template>
  <Link 
    :href="route('show.view', show.slug)"
    class="show-tile group relative block overflow-hidden rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105"
  >
    <!-- Thumbnail Container -->
    <div class="aspect-video relative bg-primary-900 overflow-hidden">
      <!-- Thumbnail Image -->
      <img 
        v-if="currentThumbnail"
        :src="currentThumbnail"
        :alt="show.title"
        class="w-full h-full object-cover"
        @error="handleImageError"
      />
      
      <!-- Placeholder when no thumbnail -->
      <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-800 to-primary-900">
        <FaVideoIcon class="w-20 h-20 text-primary-600" />
      </div>
      
      <!-- Live Badge -->
      <div v-if="isLive" class="absolute top-3 left-3 flex items-center space-x-2">
        <span class="live-badge bg-red-600 text-white px-3 py-1.5 rounded text-sm font-bold uppercase flex items-center">
          <span class="live-dot"></span>
          LIVE
        </span>
        <span v-if="show.viewer_count > 0" class="bg-black/70 text-white px-3 py-1.5 rounded text-sm">
          <FaIconUser class="inline w-3 h-3 mr-1" />
          {{ formatViewerCount(show.viewer_count) }}
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
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import FaVideoIcon from '../Icons/FaVideoIcon.vue';
import FaIconUser from '../Icons/FaIconUser.vue';
import FaPlayIcon from '../Icons/FaPlayIcon.vue';

// Props
const props = defineProps({
  show: {
    type: Object,
    required: true,
  }
});

// Reactive state
const currentThumbnail = ref(props.show.thumbnail_url);
let updateInterval = null;

// Computed properties
const isLive = computed(() => props.show.status === 'live');
const isUpcoming = computed(() => props.show.status === 'scheduled');

// Methods
const handleImageError = () => {
  currentThumbnail.value = null;
};

const formatViewerCount = (count) => {
  if (count >= 1000) {
    return (count / 1000).toFixed(1) + 'K';
  }
  return count.toString();
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
    hour: 'numeric', 
    minute: '2-digit',
    hour12: true 
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
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
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
  Echo.leave(`show.${props.show.id}`);
});
</script>

<style>
.live-badge {
  animation: pulse 2s ease-in-out infinite;
}

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