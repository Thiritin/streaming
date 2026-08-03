<template>
  <component
    :is="isPending ? 'div' : Link"
    :href="isPending ? undefined : route('recordings.show', recording.id)"
    class="group block"
    :class="[{ 'is-pending': isPending }, isPending ? '' : 'media-tile']"
    :aria-disabled="isPending ? 'true' : undefined"
    :prefetch="isPending ? undefined : true"
    @pointerdown="isPending || claimMediaHero(thumbnail)"
  >
    <!-- Thumbnail Container. Also the origin of the shared-element morph into the
         recording player, which is why it carries the ref. -->
    <div
      ref="thumbnail"
      class="aspect-video relative bg-primary-900 rounded-xl overflow-hidden ring-1 ring-white/5 transition-all duration-(--dur-base)"
      :class="isPending
        ? 'ring-white/10'
        : 'group-hover:ring-2 group-hover:ring-primary-500/60 group-hover:shadow-lg group-hover:shadow-primary-500/20'"
    >
      <!-- Thumbnail. The fade is driven by `load`, not by mount: an archive grid
           scrolls past dozens of these and a still that pops in when its bytes
           land is the thing that reads as cheap. -->
      <img
        v-if="recording.thumbnail_url && !isPending && !thumbnailError"
        :src="recording.thumbnail_url"
        :alt="recording.title"
        :loading="priority ? 'eager' : 'lazy'"
        :fetchpriority="priority ? 'high' : 'auto'"
        decoding="async"
        class="w-full h-full object-cover absolute inset-0 transition-[opacity,filter,transform] duration-(--dur-slow) ease-(--ease-out-expo) group-hover:scale-105"
        :class="thumbnailLoaded ? 'opacity-100 blur-0' : 'opacity-0 blur-md'"
        @load="thumbnailLoaded = true"
        @error="handleImageError"
      />

      <!-- Placeholder when no thumbnail -->
      <TilePlaceholder v-if="isPending || !recording.thumbnail_url || thumbnailError" :label="isPending ? null : recordingYear" />

      <!-- Loading art for a lazy thumbnail that has not decoded yet. Distinct from
           the pending shimmer below, which means the recording itself is not ready. -->
      <div
        v-else-if="!thumbnailLoaded"
        class="media-skeleton"
        aria-hidden="true"
      />

      <!-- Pending: the art is a slow shimmer instead of a spinner, so a grid of
           unprocessed shows reads as one calm surface rather than a wall of spinners. -->
      <template v-if="isPending">
        <div class="pending-shimmer" aria-hidden="true" />
        <div class="absolute inset-0 flex items-center justify-center px-4">
          <span class="pending-chip">
            <span class="pending-dot" aria-hidden="true" />
            Processing
          </span>
        </div>
      </template>

      <template v-else>
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
        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-(--dur-base) z-10" />
        <div class="absolute inset-0 flex items-center justify-center z-10">
          <div class="w-14 h-14 rounded-full bg-primary-500/90 flex items-center justify-center opacity-0 group-hover:opacity-100 transform scale-50 group-hover:scale-100 transition-all duration-(--dur-base) ease-(--ease-spring) shadow-lg shadow-primary-500/30">
            <FaPlayIcon class="w-6 h-6 text-white ml-0.5" />
          </div>
        </div>
      </template>
    </div>

    <!-- Content -->
    <div class="mt-3">
      <!-- Title -->
      <h3
        class="font-semibold text-sm leading-tight line-clamp-2 transition-colors"
        :class="isPending ? 'text-primary-300' : 'text-white group-hover:text-primary-300'"
      >
        {{ recording.title }}
      </h3>

      <!-- Date and views inline -->
      <p class="text-primary-500 text-sm mt-1">
        {{ formatDate(recording.date) }}
      </p>

      <p v-if="isPending" class="text-primary-400 text-xs mt-1">
        Still processing, check back later.
      </p>
    </div>
  </component>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TilePlaceholder from '../TilePlaceholder.vue';
import FaPlayIcon from '../Icons/FaPlayIcon.vue';
import FaEyeIcon from '../Icons/FaEyeIcon.vue';
import { claimMediaHero } from '@/composables/useMediaHero';

// Props
const props = defineProps({
  recording: {
    type: Object,
    required: true,
  },
  // Set on the tiles the first screen shows. Everything below the fold loads
  // lazily, so a year page does not open a request per still on navigation.
  priority: {
    type: Boolean,
    default: false,
  }
});

// State
const thumbnailError = ref(false);
const thumbnailLoaded = ref(false);
const thumbnail = ref(null);

// A show that has ended but has no published recording yet. It sits in the same grid
// as everything else, dimmed and unclickable, so the year does not look like it is
// missing shows without explanation.
const isPending = computed(() => Boolean(props.recording.is_pending));

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
  if (!dateString) return '';

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

.is-pending {
  @apply cursor-default opacity-60;
}

/* Light sweeping across the placeholder art: motion that says "working" without
   the jitter of a spinner on every tile in the grid. */
.pending-shimmer {
  position: absolute;
  inset: 0;
  z-index: 10;
  background: linear-gradient(
    100deg,
    transparent 35%,
    color-mix(in oklch, var(--color-primary-200) 14%, transparent) 50%,
    transparent 65%
  );
  background-size: 250% 100%;
  animation: pending-sweep 2.8s ease-in-out infinite;
}

@keyframes pending-sweep {
  0% { background-position: 150% 0; }
  100% { background-position: -50% 0; }
}

.pending-chip {
  @apply relative z-20 inline-flex items-center gap-2 rounded-full bg-black/55 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-primary-100 backdrop-blur-sm;
}

.pending-dot {
  @apply h-1.5 w-1.5 rounded-full bg-primary-300;
  animation: pending-pulse 1.6s ease-in-out infinite;
}

@keyframes pending-pulse {
  0%, 100% { opacity: 0.25; transform: scale(0.8); }
  50% { opacity: 1; transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .pending-shimmer,
  .pending-dot {
    animation: none;
  }
}
</style>
