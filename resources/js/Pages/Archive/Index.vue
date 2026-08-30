<template>
  <div class="min-h-screen">
    <Head title="Archive" />

    <div class="mx-auto max-w-page page-gutter pt-6 pb-4 sm:pt-8 sm:pb-6">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between sm:gap-5">
        <div class="space-y-1.5 sm:space-y-2">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300">Archive</p>
          <h1 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">Past streams</h1>
          <p class="text-primary-300 text-sm">
            {{ totalRecordings }} {{ totalRecordings === 1 ? 'recording' : 'recordings' }} across
            {{ chips.collections.length }} {{ chips.collections.length === 1 ? 'event' : 'events' }}.
          </p>
        </div>

        <div class="flex w-full items-center gap-2 sm:w-auto">
          <ArchiveSearch v-model="searchQuery" @submit="applySearch" />
          <ArchiveBell v-if="notifications" :notifications="notifications" />
        </div>
      </div>
    </div>

    <!-- The chip bar rides the top of the viewport: on a long grid it is the only
         way back out of a filter without scrolling to the top. -->
    <div class="chip-dock">
      <div class="mx-auto max-w-page page-gutter py-2 sm:py-3">
        <div class="flex items-center gap-2 sm:gap-3">
          <ArchiveChips class="min-w-0 flex-1" :chips="chips" :filters="filters" @select="applyFilters" />

          <!-- Clear sits with the controls it undoes rather than above the grid: the
               dock is what stays on screen once the page is scrolled, so it was the
               one control that could not be reached from where a filter is felt. -->
          <Link
            v-if="isFiltered"
            :href="route('recordings.index')"
            class="clear-filters"
            aria-label="Clear filters"
          >
            <svg class="size-4 sm:hidden" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <path d="M5.5 5.5l9 9M14.5 5.5l-9 9" />
            </svg>
            <span class="hidden sm:inline">Clear</span>
          </Link>

          <!-- The select is the control; the face is what is drawn. On a phone the
               face is the icon alone, because a chip row that has to share the
               width with a spelled-out sort has no room left to be a chip row. -->
          <div class="sort-control">
            <label class="sr-only" for="archive-sort">Sort</label>
            <select id="archive-sort" v-model="sort" @change="applyFilters({ sort })">
              <option value="newest">Newest</option>
              <option value="oldest">Oldest</option>
              <option value="views">Most viewed</option>
              <option value="longest">Longest</option>
            </select>
            <span class="sort-face" aria-hidden="true">
              <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 4v12M6 16l-2.5-2.5M6 16l2.5-2.5M11 5.5h6M11 10h4.5M11 14.5h3" />
              </svg>
              <span class="sort-face-label">{{ sortLabel }}</span>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Neither gutter nor width cap on the column: the shelf rails inside it
         scroll sideways and have to reach the edge of the window, or a rail is
         cut off mid-tile in the middle of a wide page. The gutter and the cap
         come back on each section - and inside the shelf, on its heading and as
         the rail's own inner padding. See `.page-gutter` in app.css. -->
    <div class="pt-6 pb-16">
      <!-- The only shelf: what this viewer has half-watched. Gone the moment a
           filter is on, because then the grid is the answer. -->
      <RecordingShelf
        v-if="continueWatching.length"
        title="Continue watching"
        :recordings="continueWatching"
        eager
      />

      <!-- Unfiltered: one rail per category, most watched category first. Ask
           anything of the page and it becomes the grid underneath instead. -->
      <template v-if="shelves.length">
        <RecordingShelf
          v-for="(shelf, index) in shelves"
          :key="shelf.key"
          :title="shelf.title"
          :href="shelf.count > shelf.recordings.length ? shelf.href : null"
          :recordings="shelf.recordings"
          :eager="index === 0 && !continueWatching.length"
        />
      </template>

      <div v-else class="mx-auto max-w-page">
        <!-- One count, in one place. Grouped, each row carries its own in the same
             lettering, so the page does not say how many there are and then say it
             again per run directly underneath. -->
        <div v-if="!sections.length" class="page-gutter flex flex-wrap items-center justify-between gap-3 pb-4">
          <h2 class="results-label">{{ resultsLabel }}</h2>
        </div>

        <!-- Read run by run when a category is on and no run is picked: this run's
             four theatre pieces are not the tail of last run's nine. -->
        <template v-if="sections.length">
          <section v-for="section in sections" :key="section.key" class="page-gutter pb-8">
            <h2 class="results-label pb-3">{{ section.heading }}</h2>

            <div class="stream-grid">
              <RecordingTile
                v-for="(recording, index) in section.recordings"
                :key="recording.id"
                :recording="recording"
                :priority="section.first && index < 8"
              />
            </div>
          </section>
        </template>

        <div v-else-if="tiles.length" class="page-gutter stream-grid">
          <RecordingTile
            v-for="(recording, index) in tiles"
            :key="recording.id"
            :recording="recording"
            :priority="index < 8"
          />
        </div>

        <p v-else class="page-gutter py-16 text-center text-primary-400">
          {{ isFiltered ? 'Nothing matches that.' : 'Nothing here yet.' }}
        </p>
      </div>

      <!-- Next page loads when this comes into view. -->
      <div class="mx-auto max-w-page">
        <WhenVisible
          v-if="hasMore && !filtering"
          :key="`more-${filters.event ?? filters.year ?? 'all'}-${filters.category ?? 'any'}-${filters.sort}`"
          :params="{
            data: { page: pagination.page + 1 },
            only: ['recordings', 'pagination'],
            preserveUrl: true,
          }"
          always
        >
          <template #fallback>
            <p class="page-gutter py-10 text-center text-sm text-primary-400">Loading more...</p>
          </template>
          <p class="page-gutter py-10 text-center text-sm text-primary-400">Loading more...</p>
        </WhenVisible>
      </div>
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
import ArchiveBell from '@/Components/Recordings/ArchiveBell.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  recordings: { type: Array, default: () => [] },
  // One rail per category, most watched category first. The unfiltered page only:
  // empty as soon as a chip, a search or a sort is on, and the grid takes over.
  shelves: { type: Array, default: () => [] },
  // Half-watched, most recent first. Empty for a guest and on a filtered page.
  continueWatching: { type: Array, default: () => [] },
  // Shows that ended but have not been published yet. Page one only.
  pending: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({ page: 1, lastPage: 1, total: 0 }) },
  // One entry per run, when the grid is read run by run. Empty otherwise.
  groups: { type: Array, default: () => [] },
  chips: { type: Object, default: () => ({ collections: [], categories: [] }) },
  filters: {
    type: Object,
    default: () => ({ search: null, event: null, year: null, category: null, sort: 'newest' }),
  },
  totalRecordings: { type: Number, default: 0 },
  // The whole notification settings payload: the bell opens it in a dialog. Null for
  // a guest and for an installation with the feature off, which is what keeps the bell
  // off the page rather than on it and inert.
  notifications: { type: Object, default: null },
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

const SORT_LABELS = {
  newest: 'Newest',
  oldest: 'Oldest',
  views: 'Most viewed',
  longest: 'Longest',
};

const sortLabel = computed(() => SORT_LABELS[sort.value] ?? SORT_LABELS.newest);

const isFiltered = computed(
  () =>
    Boolean(
      props.filters.search
      || props.filters.event
      || props.filters.year
      || props.filters.category
    )
    || props.filters.sort !== 'newest'
);

// Processing shows lead the grid: they are the most recent thing that happened,
// and a viewer hunting for one should find it where they expect it.
const tiles = computed(() => [...props.pending, ...props.recordings]);

const hasMore = computed(() => props.pagination.page < props.pagination.lastPage);

/*
 * The tiles sliced into runs. The server orders them by run and hands back a count
 * per run, so a section starts wherever the run changes and its heading can say how
 * many there are in total rather than how many have been scrolled in so far.
 */
const sections = computed(() => {
  if (!props.groups.length) return [];

  const counts = new Map(props.groups.map((group) => [group.label ?? '', group.count]));
  const category = props.chips.categories.find((entry) => entry.slug === props.filters.category);
  const noun = category?.name ?? 'recording';
  const out = [];

  for (const recording of tiles.value) {
    const label = recording.event_label ?? '';
    const last = out[out.length - 1];

    if (!last || last.label !== label) {
      const total = counts.get(label) ?? 0;

      out.push({
        key: label || 'unfiled',
        label,
        first: out.length === 0,
        // Same shape as the label over an ungrouped grid - "4 Theater" - with the
        // run it belongs to on the end, because that is what the row is.
        heading: label ? `${total} ${noun} ${label}` : `${total} ${noun} filed under no event`,
        recordings: [],
      });
    }

    out[out.length - 1].recordings.push(recording);
  }

  return out;
});

/*
 * True while a filter visit is in flight. The infinite-scroll sentinel comes off
 * the page for that moment: it fires on `always`, so an empty grid puts it back in
 * view mid-visit, and its partial reload would cancel the filter's own request -
 * leaving the URL on the new filter and the props on the old one.
 */
const filtering = ref(false);

const resultsLabel = computed(() => {
  const total = props.pagination.total ?? 0;
  const noun = total === 1 ? 'recording' : 'recordings';

  if (props.filters.search) {
    return `${total} ${noun} for "${props.filters.search}"`;
  }

  if (!isFiltered.value) {
    return 'All recordings';
  }

  const category = props.chips.categories.find((entry) => entry.slug === props.filters.category);

  // Only look a collection up when one is actually on: an event chip carries
  // `year: null`, so an unfiltered lookup matches the first of them and the
  // heading names a run the grid was never narrowed to.
  const collection = props.filters.event || props.filters.year
    ? props.chips.collections.find((entry) =>
      props.filters.event ? entry.event === props.filters.event : entry.year === props.filters.year
    )
    : null;

  return [total, category?.name ?? noun, collection?.label].filter(Boolean).join(' ');
});

// Every control funnels through here, so a chip does not drop the search and a
// sort does not drop the chip.
const applyFilters = (changes) => {
  const next = {
    search: searchQuery.value || null,
    event: props.filters.event,
    year: props.filters.year,
    category: props.filters.category,
    sort: sort.value,
    ...changes,
  };

  const query = {};
  if (next.search) query.search = next.search;
  if (next.event) query.event = next.event;
  if (next.year) query.year = next.year;
  if (next.category) query.category = next.category;
  if (next.sort && next.sort !== 'newest') query.sort = next.sort;

  filtering.value = true;

  router.get(route('recordings.index'), query, {
    /*
     * Deliberately not preserveState: the component is rebuilt from the response
     * rather than kept and re-fed. Keeping it is what let a chip land in the URL
     * while the chips and the grid still showed the filter before it - the page
     * had nothing to re-render from if anything cancelled or raced the visit.
     * There is no state here worth keeping: the search box and the sort both read
     * from props.
     */
    preserveState: false,
    /*
     * Back to the top, deliberately. A filter is asked from the dock, which is
     * sticky, so it can be pressed from halfway down a page of rails - and
     * keeping that offset lands the viewer in the middle of a grid that is now
     * a fraction of the length, sometimes past the end of it entirely. The
     * answer to a filter starts at the top of the answer.
     */
    preserveScroll: false,
    replace: true,
    onFinish: () => (filtering.value = false),
    /*
     * No `reset` here, deliberately. It reads like the way to stop the grid being
     * appended to, but Inertia sends it as a partial reload for that one prop, so
     * the server answers with `recordings` alone and the chips, the filters and
     * Continue watching keep whatever they had - a chip that changed the URL and
     * the grid and nothing else. The server decides instead: only page two and up
     * come back as a merge prop.
     */
  });
};

const applySearch = (term) => {
  searchQuery.value = term;
  applyFilters({ search: term || null });
};
</script>

<style scoped>
@reference "../../../css/app.css";

/* What a count of recordings looks like, wherever one is written: over the whole
   grid, or over one run's row of it. */
.results-label {
  @apply text-sm font-semibold uppercase tracking-[0.12em] text-primary-300;
}

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
  @apply inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-lg border border-white/10 px-2 py-1.5 text-sm font-medium text-primary-200 transition-colors sm:px-3;
}

.clear-filters:hover {
  @apply border-white/25 text-white;
}

.sort-control {
  @apply relative shrink-0;
}

.sort-control select {
  @apply absolute inset-0 h-full w-full cursor-pointer opacity-0;
  appearance: none;
}

.sort-face {
  @apply inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-primary-950/70 px-2 py-1.5 text-sm text-primary-100 transition-colors sm:px-3;
}

.sort-face-label {
  @apply hidden sm:inline;
}

.sort-control:hover .sort-face {
  @apply border-white/25 text-white;
}

.sort-control:focus-within .sort-face {
  @apply border-primary-400 ring-2 ring-primary-500/30;
}
</style>
