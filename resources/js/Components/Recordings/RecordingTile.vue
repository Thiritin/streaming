<template>
  <component
    :is="isPending ? 'div' : Link"
    :href="isPending ? undefined : route('recordings.show', recording.id)"
    class="group block"
    :class="[{ 'is-pending': isPending }, isPending ? '' : 'media-tile']"
    :aria-disabled="isPending ? 'true' : undefined"
    :prefetch="isPending ? undefined : true"
    @pointerdown="claimHero"
    @keydown.enter="claimHero"
    @pointerenter="onPointerEnter"
    @pointerleave="leave"
    @focusin="onPointerEnter"
    @focusout="leave"
  >
    <!-- Thumbnail Container. Also the origin of the shared-element morph into the
         recording player, which is why it carries the ref. -->
    <div
      ref="thumbnail"
      @pointermove="onScrub"
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
        :class="[
          thumbnailLoaded ? 'opacity-100 blur-0' : 'opacity-0 blur-md',
          previewPlaying ? '!opacity-0' : '',
        ]"
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

      <!-- Hover preview: the recording's own playlist, muted, lowest rendition,
           a little way in. Only ever one of these playing on the page. -->
      <video
        v-if="previewMounted"
        ref="previewVideo"
        class="absolute inset-0 h-full w-full object-cover transition-opacity duration-(--dur-base)"
        :class="previewPlaying ? 'opacity-100' : 'opacity-0'"
        muted
        playsinline
        disablepictureinpicture
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
        <!-- Bottom left: View Count. Hidden while previewing, same as YouTube
             clears its badges once the preview takes the frame. -->
        <div
          v-if="recording.views > 0"
          class="absolute bottom-2 left-2 z-20 transition-opacity"
          :class="previewPlaying ? 'opacity-0' : 'opacity-100'"
        >
          <span class="bg-black/70 text-white px-2 py-0.5 rounded text-[10px] font-medium flex items-center gap-1">
            <FaEyeIcon class="w-3 h-3" />
            {{ formatViews(recording.views) }}
          </span>
        </div>

        <!-- Bottom right: Duration, and where the preview has got to while one is
             playing - the badge is the only place a scrub can report itself. -->
        <div v-if="recording.duration" class="absolute bottom-2 right-2 z-20">
          <span class="bg-black/80 text-white px-1.5 py-0.5 rounded text-[10px] font-medium tabular-nums">
            {{ previewPlaying ? formatDuration(Math.floor(previewTime)) : formatDuration(recording.duration) }}
          </span>
        </div>

        <!-- Scrub bar for the preview: one chunk per position the cursor can pick,
             so what the bar shows and what a sweep across the tile does are the
             same thing. Replaces the watched bar while it is up. -->
        <div v-if="previewPlaying" class="scrub-track" aria-hidden="true">
          <span v-for="index in previewChunks" :key="index" class="scrub-chunk">
            <span class="scrub-chunk-fill" :style="{ transform: `scaleX(${previewChunkFill(index - 1)})` }" />
          </span>
        </div>

        <!-- How far this viewer got. Sits on the very bottom edge, under the
             badges, so it reads as part of the thumbnail rather than as content. -->
        <div v-else-if="progressFraction > 0" class="progress-track" aria-hidden="true">
          <div class="progress-fill" :style="{ width: `${Math.min(100, progressFraction * 100)}%` }" />
        </div>

        <!-- Hover Overlay -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-(--dur-base) z-10" />
        <div v-if="!previewPlaying" class="absolute inset-0 flex items-center justify-center z-10">
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

      <!-- One metadata line: views, then how long ago, the way a video card reads. -->
      <p class="text-primary-500 text-sm mt-1">
        <template v-if="!isPending && recording.views > 0">
          {{ formatViews(recording.views) }} {{ recording.views === 1 ? 'view' : 'views' }}
          <span aria-hidden="true"> · </span>
        </template>
        {{ formatDate(recording.date) }}
      </p>

      <p v-if="isPending" class="text-primary-400 text-xs mt-1">
        Still processing, check back later.
      </p>
      <p v-else-if="resumeLabel" class="text-primary-400 text-xs mt-1">
        {{ resumeLabel }}
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
import { useHoverPreview } from '@/composables/useHoverPreview';

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
  },
  // Off inside a shelf while it is being dragged sideways, so a scroll does not
  // start a video under the cursor.
  preview: {
    type: Boolean,
    default: true,
  },
});

// State
const thumbnailError = ref(false);
const thumbnailLoaded = ref(false);
const thumbnail = ref(null);

// A show that has ended but has no published recording yet. It sits in the same grid
// as everything else, dimmed and unclickable, so the year does not look like it is
// missing shows without explanation.
const isPending = computed(() => Boolean(props.recording.is_pending));

const {
  mounted: previewMounted,
  playing: previewPlaying,
  video: previewVideo,
  chunks: previewChunks,
  chunkFill: previewChunkFill,
  time: previewTime,
  scrubTo,
  enter,
  leave,
} = useHoverPreview(() => (isPending.value || !props.preview ? null : props.recording.preview_url));

const onPointerEnter = (event) => {
  // Touch reports a pointerenter on tap; previewing there would fight the tap.
  if (event?.pointerType === 'touch') return;

  enter();
};

// Cursor position across the tile is the position in the recording, once a
// preview is up. Before that the tile is a still and there is nothing to scrub.
const onScrub = (event) => {
  if (!previewPlaying.value || event.pointerType === 'touch') return;

  const rect = thumbnail.value?.getBoundingClientRect();
  if (!rect?.width) return;

  scrubTo((event.clientX - rect.left) / rect.width);
};

const progressFraction = computed(() => props.recording.progress?.fraction ?? 0);

const resumeLabel = computed(() => {
  const progress = props.recording.progress;

  if (!progress || progress.completed || !progress.position || progressFraction.value <= 0) {
    return null;
  }

  const left = (props.recording.duration ?? 0) - progress.position;

  if (left <= 60) return 'Almost finished';

  const minutes = Math.round(left / 60);

  return minutes >= 60
    ? `${Math.round(minutes / 60)}h left`
    : `${minutes} min left`;
});

// Placeholder art gets the year as its label, which is the useful thing to know
// about an archive tile with no still.
const recordingYear = computed(() =>
  props.recording.date ? String(new Date(props.recording.date).getFullYear()) : null
);

// Both activation paths, because Enter on a focused link fires `click` without
// ever firing `pointerdown`: without the keydown the old page would be captured
// with nothing named, and keyboard users would lose the morph.
const claimHero = () => {
  if (isPending.value) return;

  claimMediaHero(thumbnail.value);
};

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
    const years = Math.floor(diffDays / 365);
    return `${years} year${years > 1 ? 's' : ''} ago`;
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

.progress-track {
  @apply absolute inset-x-0 bottom-0 z-20 h-1 bg-black/55;
}

.progress-fill {
  @apply h-full bg-primary-400;
}

.scrub-track {
  @apply absolute inset-x-1 bottom-1 z-20 flex h-[3px] gap-[2px];
}

.scrub-chunk {
  @apply relative block h-full flex-1 overflow-hidden rounded-full bg-white/30;
}

.scrub-chunk-fill {
  @apply absolute inset-0 block rounded-full bg-primary-300;
  transform-origin: left center;
  transition: transform 120ms linear;
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
