<template>
  <div class="min-h-screen">
    <Head title="Archive" />

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-8 pb-6">
      <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-2">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300">Archive</p>
          <h1 class="text-3xl font-bold text-white tracking-tight">Past streams</h1>
          <p class="text-primary-300 text-sm">
            {{ totalRecordings }} {{ totalRecordings === 1 ? 'recording' : 'recordings' }} across
            {{ chips.collections.length }} {{ chips.collections.length === 1 ? 'event' : 'events' }}.
          </p>
        </div>

        <ArchiveSearch v-model="searchQuery" @submit="applySearch" />
      </div>
    </div>

    <!-- The chip bar rides the top of the viewport: on a long grid it is the only
         way back out of a filter without scrolling to the top. -->
    <div class="chip-dock">
      <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 py-3">
        <div class="flex items-center gap-3">
          <ArchiveChips class="min-w-0 flex-1" :chips="chips" :filters="filters" @select="applyFilters" />

          <!-- Clear sits with the controls it undoes rather than above the grid: the
               dock is what stays on screen once the page is scrolled, so it was the
               one control that could not be reached from where a filter is felt. -->
          <Link
            v-if="isFiltered"
            :href="route('recordings.index')"
            class="clear-filters"
          >
            Clear
          </Link>

          <div class="relative shrink-0">
            <label class="sr-only" for="archive-sort">Sort</label>
            <select id="archive-sort" v-model="sort" class="sort-select" @change="applyFilters({ sort })">
              <option value="newest">Newest</option>
              <option value="oldest">Oldest</option>
              <option value="views">Most viewed</option>
              <option value="longest">Longest</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-6 pb-16">
      <!-- The only shelf: what this viewer has half-watched. Gone the moment a
           filter is on, because then the grid is the answer. -->
      <RecordingShelf
        v-if="continueWatching.length"
        title="Continue watching"
        :recordings="continueWatching"
        eager
      />

      <div class="flex flex-wrap items-center justify-between gap-3 pb-4">
        <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-primary-300">
          {{ resultsLabel }}
        </h2>
      </div>

      <div v-if="tiles.length" class="stream-grid">
        <RecordingTile
          v-for="(recording, index) in tiles"
          :key="recording.id"
          :recording="recording"
          :priority="index < 8"
        />
      </div>

      <p v-else class="py-16 text-center text-primary-400">
        {{ isFiltered ? 'Nothing matches that.' : 'Nothing here yet.' }}
      </p>

      <!-- Next page loads when this comes into view. -->
      <WhenVisible
        v-if="hasMore"
        :params="{
          data: { page: pagination.page + 1 },
          only: ['recordings', 'pagination'],
          preserveUrl: true,
        }"
        always
      >
        <template #fallback>
          <p class="py-10 text-center text-sm text-primary-400">Loading more...</p>
        </template>
        <p class="py-10 text-center text-sm text-primary-400">Loading more...</p>
      </WhenVisible>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, router, WhenVisible } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import RecordingShelf from '@/Components/Recordings/RecordingShelf.vue';
import ArchiveChips from '@/Components/Recordings/ArchiveChips.vue';
import ArchiveSearch from '@/Components/Recordings/ArchiveSearch.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  recordings: { type: Array, default: () => [] },
  // Half-watched, most recent first. Empty for a guest and on a filtered page.
  continueWatching: { type: Array, default: () => [] },
  // Shows that ended but have not been published yet. Page one only.
  pending: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({ page: 1, lastPage: 1, total: 0 }) },
  chips: { type: Object, default: () => ({ collections: [], sources: [], categories: [] }) },
  filters: {
    type: Object,
    default: () => ({ search: null, event: null, year: null, source: null, category: null, sort: 'newest' }),
  },
  totalRecordings: { type: Number, default: 0 },
});

const searchQuery = ref(props.filters.search ?? '');
const sort = ref(props.filters.sort ?? 'newest');

watch(
  () => props.filters,
  (value) => {
    searchQuery.value = value.search ?? '';
    sort.value = value.sort ?? 'newest';
  }
);

const isFiltered = computed(
  () =>
    Boolean(
      props.filters.search
      || props.filters.event
      || props.filters.year
      || props.filters.source
      || props.filters.category
    )
    || props.filters.sort !== 'newest'
);

// Processing shows lead the grid: they are the most recent thing that happened,
// and a viewer hunting for one should find it where they expect it.
const tiles = computed(() => [...props.pending, ...props.recordings]);

const hasMore = computed(() => props.pagination.page < props.pagination.lastPage);

const resultsLabel = computed(() => {
  const total = props.pagination.total ?? 0;
  const noun = total === 1 ? 'recording' : 'recordings';

  if (props.filters.search) {
    return `${total} ${noun} for "${props.filters.search}"`;
  }

  if (!isFiltered.value) {
    return 'All recordings';
  }

  const source = props.chips.sources.find((entry) => entry.slug === props.filters.source);
  const category = props.chips.categories.find((entry) => entry.slug === props.filters.category);
  const collection = props.chips.collections.find((entry) =>
    props.filters.event ? entry.event === props.filters.event : entry.year === props.filters.year
  );

  return [total, category?.name ?? noun, collection?.label, source?.name].filter(Boolean).join(' ');
});

// Every control funnels through here, so a chip does not drop the search and a
// sort does not drop the chip.
const applyFilters = (changes) => {
  const next = {
    search: searchQuery.value || null,
    event: props.filters.event,
    year: props.filters.year,
    source: props.filters.source,
    category: props.filters.category,
    sort: sort.value,
    ...changes,
  };

  const query = {};
  if (next.search) query.search = next.search;
  if (next.event) query.event = next.event;
  if (next.year) query.year = next.year;
  if (next.source) query.source = next.source;
  if (next.category) query.category = next.category;
  if (next.sort && next.sort !== 'newest') query.sort = next.sort;

  router.get(route('recordings.index'), query, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const applySearch = (term) => {
  searchQuery.value = term;
  applyFilters({ search: term || null });
};
</script>

<style scoped>
@reference "../../../css/app.css";

/* Docked under the site's own bar, which is h-14 and sticky at the top. */
.chip-dock {
  @apply sticky top-14 z-30 border-b border-white/5 bg-surface-0/85 backdrop-blur;
}

.stream-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 1.75rem 1rem;
}

@media (max-width: 640px) {
  .stream-grid {
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  }
}

.clear-filters {
  @apply inline-flex shrink-0 items-center whitespace-nowrap rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-primary-200 transition-colors;
}

.clear-filters:hover {
  @apply border-white/25 text-white;
}

.sort-select {
  @apply rounded-lg border border-white/10 bg-primary-950/70 px-3 py-1.5 text-sm text-primary-100 transition-colors;
}

.sort-select:hover {
  @apply border-white/25 text-white;
}

.sort-select:focus {
  @apply border-primary-400 outline-none ring-2 ring-primary-500/30;
}
</style>
