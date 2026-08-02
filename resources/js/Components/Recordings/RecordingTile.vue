<template>
  <Link
    :href="route('recordings.show', recording.id)"
    class="group block"
  >
    <!-- Thumbnail Container -->
    <div class="aspect-video relative bg-primary-900 rounded-xl overflow-hidden ring-1 ring-white/5 group-hover:ring-2 group-hover:ring-primary-500/60 group-hover:shadow-lg group-hover:shadow-primary-500/20 transition-all duration-300">
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
          class="w-full h-full object-cover absolute inset-0 transition-transform duration-500 group-hover:scale-105"
          @error="handleImageError"
        />
      </Transition>

      <!-- Placeholder when no thumbnail -->
      <TilePlaceholder v-if="!recording.thumbnail_url || thumbnailError" :label="recordingYear" />

      <!-- Bottom left: View Count -->
      <div v-if="recording.views > 0" class="absolute bottom-2 left-2 z-20">
        <span class="bg-black/70 text-white px-2 py-0.5 rounded text-[10px] font-medium flex items-center gap-1">
          <FaEyeIcon class="w-3 h-3" />
          {{ formatViews(recording.views) }}
        </span>
      </div>

      <!-- Bottom right: Duration -->
      <div v-if="recording.duration" class="absolute bottom-2 right-2 z-20">
        <span class="bg-black/80 text-white px-1.5 py-0.5 rounded text-[10px] font-medium tabular-nums">
          {{ formatDuration(recording.duration) }}
        </span>
      </div>

      <!-- Hover Overlay -->
      <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10" />
      <div class="absolute inset-0 flex items-center justify-center z-10">
        <div class="w-14 h-14 rounded-full bg-primary-500/90 flex items-center justify-center opacity-0 group-hover:opacity-100 transform scale-50 group-hover:scale-100 transition-all duration-300 shadow-lg shadow-primary-500/30">
          <FaPlayIcon class="w-6 h-6 text-white ml-0.5" />
        </div>
      </div>
    </div>

    <!-- Content -->
    <div class="mt-3">
      <!-- Title -->
      <h3 class="font-semibold text-white text-sm leading-tight line-clamp-2 group-hover:text-primary-300 transition-colors">
        {{ recording.title }}
      </h3>

      <!-- Date and views inline -->
      <p class="text-primary-500 text-sm mt-1">
        {{ formatDate(recording.date) }}
      </p>
    </div>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TilePlaceholder from '../TilePlaceholder.vue';
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

// Placeholder art gets the year as its label, which is the useful thing to know
// about an archive tile with no still.
const recordingYear = computed(() =>
  props.recording.date ? String(new Date(props.recording.date).getFullYear()) : null
);

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

<style>
@reference "../../../css/app.css";

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>