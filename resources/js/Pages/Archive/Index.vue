<template>
  <div class="min-h-screen">
    <Head title="Archive" />

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-8 pb-4">
      <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-2">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300">Archive</p>
          <h1 class="text-3xl font-bold text-white tracking-tight">Every convention, one collection each</h1>
          <p class="text-primary-300 text-sm">
            {{ totalRecordings }} recordings across {{ collections.length }}
            {{ collections.length === 1 ? 'year' : 'years' }}.
          </p>
        </div>

        <form class="w-full sm:w-72" @submit.prevent="submitSearch">
          <label class="sr-only" for="archive-search">Search the archive</label>
          <input
            id="archive-search"
            v-model="searchQuery"
            type="search"
            placeholder="Search titles and descriptions"
            class="search-input"
          />
        </form>
      </div>
    </div>

    <!-- Search takes over the page: flat results, no year grouping -->
    <div v-if="searchResults" class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pb-14">
      <div class="flex items-center justify-between pb-4">
        <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-primary-300">
          {{ searchResults.length }} {{ searchResults.length === 1 ? 'result' : 'results' }} for "{{ search }}"
        </h2>
        <Link :href="route('recordings.index')" class="text-sm text-primary-300 hover:text-white transition-colors">
          Clear search
        </Link>
      </div>

      <TransitionGroup v-if="searchResults.length" tag="div" name="tile" appear class="stream-grid">
        <RecordingTile
          v-for="(recording, index) in searchResults"
          :key="recording.id"
          :recording="recording"
          :priority="index < 8"
          :style="{ '--stagger': Math.min(index, 12) }"
        />
      </TransitionGroup>
      <p v-else class="py-16 text-center text-primary-400">Nothing matches that search.</p>
    </div>

    <template v-else>
      <!-- Year collections -->
      <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pb-10">
        <div v-if="collections.length" class="collection-grid">
          <Link
            v-for="(collection, index) in collections"
            :key="collection.year"
            :href="route('recordings.year', collection.year)"
            class="collection-card group reveal"
            prefetch
          >
            <div class="collection-art">
              <img
                v-if="collection.thumbnail_url && artState[collection.year] !== 'failed'"
                :src="collection.thumbnail_url"
                :alt="`${collection.year} collection`"
                :loading="index < 4 ? 'eager' : 'lazy'"
                :fetchpriority="index < 4 ? 'high' : 'auto'"
                decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition-[opacity,transform] duration-(--dur-slow) ease-(--ease-out-expo) group-hover:scale-105"
                :class="artState[collection.year] === 'loaded' ? 'opacity-100' : 'opacity-0'"
                @load="artState[collection.year] = 'loaded'"
                @error="artState[collection.year] = 'failed'"
              />
              <TilePlaceholder v-else />

              <!-- Only while the art is genuinely in flight. A failed request sets
                   `failed` too, so the sweep stops and the placeholder takes over
                   rather than shimmering at an image that is never coming. -->
              <div
                v-if="collection.thumbnail_url && !artState[collection.year]"
                class="media-skeleton"
                aria-hidden="true"
              />

              <div class="collection-scrim" />

              <div class="collection-overlay">
                <span class="collection-year">{{ collection.year }}</span>
                <span class="collection-count tabular-nums">
                  {{ collection.count }} {{ collection.count === 1 ? 'show' : 'shows' }}
                </span>
              </div>
            </div>

            <div class="collection-body">
              <p class="collection-stats tabular-nums">
                <span>{{ collection.hours }}h runtime</span>
                <span aria-hidden="true">·</span>
                <span>{{ formatViews(collection.total_views) }} views</span>
              </p>
              <p v-if="collection.highlights.length" class="collection-highlights">
                {{ collection.highlights.join(' · ') }}
              </p>
            </div>
          </Link>
        </div>

        <p v-else class="py-16 text-center text-primary-400">
          The archive is empty. Shows appear here once they finish processing.
        </p>
      </div>

      <!-- Latest additions, same tiles as browse -->
      <div v-if="recentRecordings.length" class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pb-14">
        <div class="flex items-center justify-between pb-4">
          <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-primary-300">Latest additions</h2>
          <Link
            v-if="collections.length"
            :href="route('recordings.year', collections[0].year)"
            class="text-sm text-primary-300 hover:text-white transition-colors"
          >
            All of {{ collections[0].year }}
          </Link>
        </div>

        <TransitionGroup tag="div" name="tile" appear class="stream-grid">
          <!-- Below the year collections, so these always load lazily. -->
          <RecordingTile
            v-for="(recording, index) in recentRecordings"
            :key="recording.id"
            :recording="recording"
            :style="{ '--stagger': Math.min(index, 12) }"
          />
        </TransitionGroup>
      </div>
    </template>
  </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import TilePlaceholder from '@/Components/TilePlaceholder.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  collections: { type: Array, default: () => [] },
  // Published recordings plus the shows still processing, flagged `is_pending` and
  // rendered as dimmed tiles in the same grid.
  recentRecordings: { type: Array, default: () => [] },
  searchResults: { type: Array, default: null },
  totalRecordings: { type: Number, default: 0 },
  search: { type: String, default: null },
});

const searchQuery = ref(props.search ?? '');

// Keyed by year, 'loaded' | 'failed': collection art fades in when its own bytes
// land, same as a tile, and falls back to the placeholder when they never do.
const artState = reactive({});

const submitSearch = () => {
  router.get(
    route('recordings.index'),
    searchQuery.value ? { search: searchQuery.value } : {},
    { preserveState: true, preserveScroll: true, replace: true }
  );
};

const formatViews = (views) => {
  if (views >= 1000000) return `${(views / 1000000).toFixed(1)}M`;
  if (views >= 1000) return `${(views / 1000).toFixed(1)}K`;
  return String(views ?? 0);
};
</script>

<style scoped>
@reference "../../../css/app.css";

/* Collection cards are wider than show tiles: a year is a bigger thing to click
   than a single recording, and the poster needs room to read as cover art. */
.collection-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1.25rem;
}

.collection-card {
  @apply flex flex-col overflow-hidden rounded-xl bg-primary-950/50 ring-1 ring-white/5 transition-all duration-(--dur-base);
}

.collection-card:hover {
  @apply ring-2 ring-primary-500/60 shadow-lg shadow-primary-500/20;
}

.collection-card:focus-visible {
  @apply outline-none ring-2 ring-primary-300;
}

.collection-art {
  @apply relative aspect-[3/2] overflow-hidden bg-primary-800;
}

.collection-scrim {
  @apply absolute inset-0;
  background: linear-gradient(to top, oklch(17.17% 0.031 183.02 / 0.92) 0%, oklch(17.17% 0.031 183.02 / 0.25) 55%, transparent 100%);
}

.collection-overlay {
  @apply absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 p-4;
}

.collection-year {
  @apply text-3xl font-bold leading-none tracking-tight text-white tabular-nums;
}

.collection-count {
  @apply rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-primary-100;
}

.collection-body {
  @apply flex flex-col gap-1 px-4 py-3;
}

.collection-stats {
  @apply flex flex-wrap items-center gap-2 text-xs text-primary-300;
}

.collection-highlights {
  @apply truncate text-xs text-primary-400;
}

.stream-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 1.25rem 1rem;
}

.search-input {
  @apply w-full rounded-full border border-primary-700 bg-primary-950/60 px-4 py-2 text-sm text-white placeholder:text-primary-500 transition-colors;
}

.search-input:focus {
  @apply border-primary-400 outline-none ring-2 ring-primary-500/30;
}
</style>
