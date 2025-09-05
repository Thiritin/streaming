<template>
  <div class="recording-tile group relative block overflow-hidden rounded-lg shadow-lg opacity-60 cursor-not-allowed">
    <!-- Thumbnail Container -->
    <div class="aspect-video relative bg-primary-900 overflow-hidden">
      <!-- Placeholder -->
      <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-800 to-primary-900">
        <div class="text-center">
          <FaSpinnerIcon class="w-16 h-16 text-white opacity-50 mx-auto mb-3 animate-spin" />
          <span class="text-white text-sm font-medium">Processing Recording...</span>
        </div>
      </div>
      
      <!-- Status Badge -->
      <div class="absolute top-3 right-3">
        <span class="bg-yellow-600/90 text-white px-3 py-1 rounded text-xs font-medium flex items-center">
          <FaClockIcon class="w-3 h-3 mr-1" />
          Pending
        </span>
      </div>
    </div>
    
    <!-- Content -->
    <div class="p-4 bg-primary-800/80">
      <!-- Title -->
      <h3 class="font-semibold text-lg text-white/80 truncate">
        {{ show.title }}
      </h3>
      
      <!-- Description -->
      <p v-if="show.description" class="text-base text-primary-400/80 mt-1 line-clamp-2">
        {{ show.description }}
      </p>
      
      <!-- Date and Status -->
      <div class="mt-2 flex items-center justify-between">
        <p class="text-base text-primary-500">
          {{ formatDate(show.scheduled_end || show.actual_end) }}
        </p>
        <p class="text-xs text-yellow-500">
          Recording in progress
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import FaSpinnerIcon from '../Icons/FaSpinnerIcon.vue';
import FaClockIcon from '../Icons/FaClockIcon.vue';

// Props
const props = defineProps({
  show: {
    type: Object,
    required: true,
  }
});

// Methods
const formatDate = (dateString) => {
  if (!dateString) return 'Unknown date';
  
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
</script>

<style scoped>
.recording-tile {
  background-color: rgb(0 59 50); /* primary-700 */
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>