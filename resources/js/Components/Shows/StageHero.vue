<template>
  <section class="stage-hero">
    <!-- Backdrop is pure CSS on purpose: a blurred full-bleed <img> costs a filter
         pass on every scrolled frame, and a gradient reads the same behind a panel. -->
    <div class="absolute inset-0 stage-backdrop" aria-hidden="true" />

    <div class="relative mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-6 pb-8">
      <!-- One channel on: the frame alone, centred and capped by width. Capping the
           height of a 16:9 box instead makes the ratio transfer back into the width,
           so the box narrows while its track stays 1fr and leaves a band of empty
           frame beside the picture. 62vh of height is 62 * 16/9 = 110.22vh of width,
           which is the same ceiling with nothing left over.

           More than one on: tracks of 2fr and 1fr, because that is what makes two
           stacked 16:9 boxes come out the height of one twice their width. The row is
           capped at 1.5x the single-channel ceiling so the featured track keeps it. -->
      <div
        class="mx-auto"
        :class="hasSideShows
          ? 'grid gap-4 lg:gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)] lg:max-w-[165.33vh]'
          : 'w-full max-w-[110.22vh]'"
      >
        <!-- Featured channel. The whole frame is a link into the show page: playing
             it inline with no controls is what had people stuck on the front page
             looking for a scrubber, a quality picker and chat. -->
        <LivePreview
          :show="show"
          variant="featured"
          :with-text="true"
          :with-mute="true"
          :priority="true"
          :source-status="sourceStatus"
        />

        <!-- The other channels that are on, so the page says what is happening
             everywhere at a glance rather than only on the busiest stage. -->
        <div v-if="hasSideShows" class="flex flex-col gap-4">
          <LivePreview
            v-for="side in sideShows"
            :key="side.id"
            :show="side"
            variant="compact"
            :with-text="true"
            :low-quality="true"
            :source-status="side.source_status ?? null"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import LivePreview from './LivePreview.vue';

const props = defineProps({
  show: {
    type: Object,
    required: true,
  },
  // Status of the channel behind the show, when the page tracks it. A show can be
  // live while its source is not publishing, and that is not "connecting".
  sourceStatus: {
    type: String,
    default: null,
  },
  // The other channels that are on right now. Two at most: a third row would be
  // shorter than the copy it carries, and the grid below already lists everything.
  sideShows: {
    type: Array,
    default: () => [],
  },
});

const hasSideShows = computed(() => props.sideShows.length > 0);
</script>

<style scoped>
@reference "../../../css/app.css";

.stage-hero {
  @apply relative isolate;
}

.stage-backdrop {
  background:
    radial-gradient(120% 80% at 20% 0%, color-mix(in oklab, var(--color-primary-700) 55%, transparent) 0%, transparent 60%),
    linear-gradient(to bottom, var(--color-primary-950) 0%, var(--color-primary-900) 100%);
}
</style>
