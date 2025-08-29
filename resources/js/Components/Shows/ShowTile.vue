<template>
  <Link 
    :href="route('show.view', show.id)"
    class="show-tile group relative block overflow-hidden rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105"
  >
    <!-- Thumbnail Container -->
    <div class="aspect-video relative bg-gray-900 overflow-hidden">
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
        <FaVideoIcon class="w-16 h-16 text-primary-600" />
      </div>
      
      <!-- Live Badge -->
      <div v-if="isLive" class="absolute top-2 left-2 flex items-center space-x-1">
        <span class="live-badge bg-red-600 text-white px-2 py-1 rounded text-xs font-bold uppercase flex items-center">
          <span class="live-dot"></span>
          LIVE
        </span>
        <span v-if="show.viewer_count > 0" class="bg-black/70 text-white px-2 py-1 rounded text-xs">
          <FaIconUser class="inline w-3 h-3 mr-1" />
          {{ formatViewerCount(show.viewer_count) }}
        </span>
      </div>
      
      <!-- Upcoming Time -->
      <div v-else-if="isUpcoming" class="absolute top-2 left-2">
        <span class="bg-black/70 text-white px-2 py-1 rounded text-xs">
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
        <FaPlayIcon class="text-white w-12 h-12 opacity-0 group-hover:opacity-100 transition-opacity duration-300" />
      </div>
    </div>
    
    <!-- Content -->
    <div class="p-3 bg-gray-800">
      <!-- Title -->
      <h3 class="font-semibold text-white truncate group-hover:text-primary-400 transition-colors">
        {{ show.title }}
      </h3>
      
      <!-- Source/Location -->
      <p v-if="show.source" class="text-sm text-gray-400 truncate">
        {{ show.source.name }}
        <span v-if="show.source.location" class="text-xs"> • {{ show.source.location }}</span>
      </p>
      
      <!-- Scheduled Time for Upcoming -->
      <p v-if="isUpcoming" class="text-sm text-gray-400 mt-1">
        {{ formatScheduledTime(show.scheduled_start) }}
      </p>
    </div>
  </Link>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import FaVideoIcon from '../Icons/FaVideoIcon.vue';
import FaIconUser from '../Icons/FaIconUser.vue';
import FaPlayIcon from '../Icons/FaPlayIcon.vue';

export default {
  name: 'ShowTile',
  components: {
    Link,
    FaVideoIcon,
    FaIconUser,
    FaPlayIcon,
  },
  props: {
    show: {
      type: Object,
      required: true,
    }
  },
  setup(props) {
    const currentThumbnail = ref(props.show.thumbnail_url);
    const placeholderUrl = '/images/stream-placeholder.svg';
    let updateInterval = null;
    
    const isLive = computed(() => props.show.status === 'live');
    const isUpcoming = computed(() => props.show.status === 'scheduled');
    
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
    
    // Listen for thumbnail updates
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
    
    return {
      currentThumbnail,
      isLive,
      isUpcoming,
      handleImageError,
      formatViewerCount,
      formatDuration,
      formatTimeUntil,
      formatScheduledTime,
    };
  }
};
</script>

<style scoped>
.live-badge {
  animation: pulse 2s ease-in-out infinite;
}

.live-dot {
  @apply w-2 h-2 bg-white rounded-full mr-1;
  animation: blink 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.9;
  }
}

@keyframes blink {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.3;
  }
}

.show-tile {
  @apply bg-gray-800;
}

.show-tile:hover {
  @apply bg-gray-700;
}
</style>