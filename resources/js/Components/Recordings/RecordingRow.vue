<template>
  <Link :href="route('recordings.show', recording.id)" class="row group" prefetch>
    <div class="row-art">
      <img
        v-if="recording.thumbnail_url && !thumbnailError"
        :src="recording.thumbnail_url"
        :alt="recording.title"
        loading="lazy"
        decoding="async"
        class="h-full w-full object-cover transition-transform duration-(--dur-slow) ease-(--ease-out-expo) group-hover:scale-105"
        @error="thumbnailError = true"
      />
      <TilePlaceholder v-else />

      <span v-if="recording.duration" class="row-duration tabular-nums">
        {{ formatDuration(recording.duration) }}
      </span>

      <span v-if="fraction > 0" class="row-progress" aria-hidden="true">
        <span class="row-progress-fill" :style="{ width: `${Math.min(100, fraction * 100)}%` }" />
      </span>
    </div>

    <div class="min-w-0 flex-1">
      <h3 class="line-clamp-2 text-sm font-semibold leading-snug text-white transition-colors group-hover:text-primary-300">
        {{ recording.title }}
      </h3>
      <p v-if="recording.source_name" class="mt-1 truncate text-xs text-primary-400">
        {{ recording.source_name }}
      </p>
      <p class="mt-0.5 text-xs text-primary-500">
        <template v-if="recording.views > 0">
          {{ formatViews(recording.views) }} views
          <span aria-hidden="true"> · </span>
        </template>
        {{ formatAge(recording.date) }}
      </p>
    </div>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TilePlaceholder from '../TilePlaceholder.vue';

const props = defineProps({
  recording: { type: Object, required: true },
});

const thumbnailError = ref(false);

const fraction = computed(() => props.recording.progress?.fraction ?? 0);

const formatDuration = (seconds) => {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;

  return hours > 0
    ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
    : `${minutes}:${String(secs).padStart(2, '0')}`;
};

const formatViews = (views) => {
  if (views < 1000) return String(views);
  if (views < 1000000) return `${(views / 1000).toFixed(1).replace(/\.0$/, '')}K`;
  return `${(views / 1000000).toFixed(1).replace(/\.0$/, '')}M`;
};

const formatAge = (dateString) => {
  if (!dateString) return '';

  const days = Math.floor((Date.now() - new Date(dateString)) / 86400000);

  if (days <= 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days < 30) return `${days} days ago`;
  if (days < 365) {
    const months = Math.floor(days / 30);
    return `${months} month${months > 1 ? 's' : ''} ago`;
  }

  const years = Math.floor(days / 365);
  return `${years} year${years > 1 ? 's' : ''} ago`;
};
</script>

<style scoped>
@reference "../../../css/app.css";

.row {
  @apply flex gap-3 rounded-xl p-2 transition-colors;
}

.row:hover {
  @apply bg-white/5;
}

.row:focus-visible {
  @apply outline-none ring-2 ring-primary-400;
}

.row-art {
  @apply relative aspect-video w-40 shrink-0 overflow-hidden rounded-lg bg-primary-900 ring-1 ring-white/5;
}

.row-duration {
  @apply absolute bottom-1 right-1 rounded bg-black/80 px-1 py-0.5 text-[10px] font-medium text-white;
}

.row-progress {
  @apply absolute inset-x-0 bottom-0 block h-1 bg-black/55;
}

.row-progress-fill {
  @apply block h-full bg-primary-400;
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
