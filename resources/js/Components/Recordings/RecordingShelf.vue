<template>
  <section class="shelf">
    <div class="shelf-head mx-auto flex max-w-page items-end justify-between gap-4 pb-3">
      <h2 class="shelf-title">{{ title }}</h2>

      <div class="flex items-center gap-2">
        <Link v-if="href" :href="href" class="shelf-link">See all</Link>

        <!-- Arrows only where a pointer can use them, and only when the rail has
             somewhere to go: two tiles on a wide screen need no controls. -->
        <div v-if="!atStart || !atEnd" class="hidden md:flex items-center gap-1">
          <button
            type="button"
            class="shelf-arrow"
            :disabled="atStart"
            :aria-label="`Scroll ${title} left`"
            @click="scrollBy(-1)"
          >
            <FaArrowLeftIcon class="h-3.5 w-3.5" />
          </button>
          <button
            type="button"
            class="shelf-arrow"
            :disabled="atEnd"
            :aria-label="`Scroll ${title} right`"
            @click="scrollBy(1)"
          >
            <FaArrowLeftIcon class="h-3.5 w-3.5 rotate-180" />
          </button>
        </div>
      </div>
    </div>

    <div class="shelf-viewport">
      <div
        ref="rail"
        class="shelf-rail"
        :class="{ 'is-start': atStart, 'is-end': atEnd }"
        @scroll.passive="updateEdges"
      >
        <div v-for="(recording, index) in recordings" :key="recording.id" class="shelf-item">
          <RecordingTile :recording="recording" :priority="eager && index < 5" />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount, ref } from 'vue';
import RecordingTile from './RecordingTile.vue';
import FaArrowLeftIcon from '@/Components/Icons/FaArrowLeftIcon.vue';

const props = defineProps({
  title: { type: String, required: true },
  href: { type: String, default: null },
  recordings: { type: Array, default: () => [] },
  // Set on the first shelf only, so the page above the fold does not wait on
  // lazy stills while everything below it stays lazy.
  eager: { type: Boolean, default: false },
});

const rail = ref(null);
const atStart = ref(true);
const atEnd = ref(false);

const updateEdges = () => {
  const element = rail.value;
  if (!element) return;

  atStart.value = element.scrollLeft <= 4;
  atEnd.value = element.scrollLeft + element.clientWidth >= element.scrollWidth - 4;
};

// A page at a time, minus a sliver, so the tile at the edge stays visible and
// the rail reads as continuous rather than paginated.
const scrollBy = (direction) => {
  const element = rail.value;
  if (!element) return;

  element.scrollBy({
    left: direction * (element.clientWidth * 0.9),
    behavior: 'smooth',
  });
};

onMounted(() => {
  updateEdges();
  window.addEventListener('resize', updateEdges);
});

onBeforeUnmount(() => window.removeEventListener('resize', updateEdges));
</script>

<style scoped>
@reference "../../../css/app.css";

.shelf {
  @apply pb-8;
}

.shelf-title {
  @apply text-base font-semibold text-white;
}

.shelf-link {
  @apply rounded-full px-3 py-1 text-sm text-primary-300 transition-colors hover:bg-white/5 hover:text-white;
}

.shelf-arrow {
  @apply inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/5 text-primary-200 transition-colors;
}

.shelf-arrow:hover:not(:disabled) {
  @apply bg-white/10 text-white;
}

.shelf-arrow:disabled {
  @apply cursor-default opacity-25;
}

.shelf-arrow:focus-visible {
  @apply outline-none ring-2 ring-primary-400;
}

.shelf-viewport {
  @apply relative;
}

/*
 * The rail runs the full width of the window, so on a screen wider than the page
 * cap the inset that lines the first tile up with the heading is the gutter plus
 * whatever the cap leaves at the side. A percentage, not a vw: 100% is the
 * content width, which a vertical scrollbar has already been taken out of.
 */
.shelf-rail {
  --shelf-inset: max(
    var(--page-gutter),
    calc((100% - var(--container-page)) / 2 + var(--page-gutter))
  );
}

.shelf-head {
  padding-inline: var(--page-gutter);
}

/*
 * The rail is the one thing on the page that is not inset by the gutter: it
 * scrolls sideways, so its track has to run the full width of the page or the
 * tiles disappear under a padding that is not theirs. The gutter comes back as
 * the rail's own inner padding, which puts the first tile under the heading
 * where it belongs while still letting the rest scroll out to the edge.
 *
 * Scroll padding matches it, so a keyboard tab into a tile at the edge does not
 * park it half under the fade.
 */
.shelf-rail {
  display: flex;
  gap: 1rem;
  overflow-x: auto;
  scroll-snap-type: x proximity;
  scroll-padding-inline: var(--shelf-inset);
  scrollbar-width: none;
  /* Vertical room for the tile's hover ring and lift, which the scroll
     container would otherwise clip. */
  padding-block: 0.25rem;
  padding-inline: var(--shelf-inset);
}

.shelf-rail::-webkit-scrollbar {
  display: none;
}

.shelf-item {
  flex: 0 0 auto;
  width: 260px;
  scroll-snap-align: start;
}

@media (max-width: 640px) {
  .shelf-item {
    width: 210px;
  }
}

/*
 * A hint that the rail continues, dropped at whichever end it has reached. The
 * end is masked out rather than covered by a gradient: an overlay has to be
 * painted in the colour behind the rail, and a colour that is a shade off shows
 * up as a band with two hard edges. The fade widths are registered properties so
 * the hint can still be faded in and out.
 */
@property --shelf-fade-start {
  syntax: "<length>";
  inherits: false;
  initial-value: 0px;
}

@property --shelf-fade-end {
  syntax: "<length>";
  inherits: false;
  initial-value: 0px;
}

.shelf-rail {
  --shelf-fade-start: 2.5rem;
  --shelf-fade-end: 2.5rem;
  transition:
    --shelf-fade-start var(--dur-base),
    --shelf-fade-end var(--dur-base);
  -webkit-mask-image: var(--shelf-mask);
  mask-image: var(--shelf-mask);
  --shelf-mask: linear-gradient(
    to right,
    transparent 0,
    #000 var(--shelf-fade-start),
    #000 calc(100% - var(--shelf-fade-end)),
    transparent 100%
  );
}

.shelf-rail.is-start {
  --shelf-fade-start: 0px;
}

.shelf-rail.is-end {
  --shelf-fade-end: 0px;
}

</style>
