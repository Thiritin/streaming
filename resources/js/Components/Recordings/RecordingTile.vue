<template>
  <Link 
    :href="route('recordings.show', recording.id)"
    class="recording-tile group relative block overflow-hidden rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105"
  >
    <!-- Thumbnail Container -->
    <div class="aspect-video relative bg-primary-900 overflow-hidden">
      <!-- Thumbnail Image -->
      <Transition
        enter-active-class="transition-all duration-500 ease-out"
        enter-from-class="opacity-0 blur-md"
        enter-to-class="opacity-100 blur-0"
      >
        <img 
          v-if="recording.thumbnail_url"
          :src="recording.thumbnail_url"
          :alt="recording.title"
          class="w-full h-full object-cover absolute inset-0"
          @error="handleImageError"
        />
      </Transition>
      
      <!-- Placeholder when no thumbnail -->
      <div v-if="!recording.thumbnail_url || thumbnailError" class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-800 to-primary-900">
        <FaVideoIcon class="w-20 h-20 text-white opacity-50" />
      </div>
      
      <!-- Duration Overlay -->
      <div v-if="recording.duration" class="absolute bottom-2 right-2">
        <span class="bg-black/70 text-white px-2 py-1 rounded text-xs font-medium">
          {{ formatDuration(recording.duration) }}
        </span>
      </div>
      
      <!-- View Count Overlay -->
      <div v-if="recording.views > 0" class="absolute top-3 left-3">
        <span class="bg-black/70 text-white px-2 py-1 rounded text-xs flex items-center">
          <FaEyeIcon class="w-3 h-3 mr-1" />
          {{ formatViews(recording.views) }}
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
        {{ recording.title }}
      </h3>
      
      <!-- Description -->
      <p v-if="recording.description" class="text-base text-primary-400 mt-1 line-clamp-2">
        {{ recording.description }}
      </p>
      
      <!-- Date -->
      <p class="text-base text-primary-500 mt-2">
        {{ formatDate(recording.date) }}
      </p>
    </div>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import FaVideoIcon from '../Icons/FaVideoIcon.vue';
import FaPlayIcon from '../Icons/FaPlayIcon.vue';
import FaEyeIcon from '../Icons/FaEyeIcon.vue';

// Props
const props = defineProps({
  recording: {
    type: Object,
    required: true,
  }
});

// State
const thumbnailError = ref(false);

// Methods
const handleImageError = () => {
  thumbnailError.value = true;
};

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
  const today = new Date();
  const diffTime = today - date;
  const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
  
  if (diffDays === 0) {
    return 'Today';
  } else if (diffDays === 1) {
    return 'Yesterday';
  } else if (diffDays < 7) {
    return `${diffDays} days ago`;
  } else if (diffDays < 30) {
    const weeks = Math.floor(diffDays / 7);
    return `${weeks} week${weeks > 1 ? 's' : ''} ago`;
  } else if (diffDays < 365) {
    const months = Math.floor(diffDays / 30);
    return `${months} month${months > 1 ? 's' : ''} ago`;
  } else {
    return date.toLocaleDateString('en-US', { 
      month: 'short', 
      day: 'numeric', 
      year: 'numeric'
    });
  }
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
.recording-tile {
  background-color: rgb(0 59 50); /* primary-700 */
}

.recording-tile:hover {
  background-color: rgb(0 80 75); /* primary-600 */
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>