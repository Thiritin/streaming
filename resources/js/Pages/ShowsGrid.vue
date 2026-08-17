<template>
  <div class="min-h-screen">
    <Head title="Browse" />

    <!-- Featured channel: the primary channel owns this slot, live or not -->
    <StageHero v-if="featured" :show="featured" :chat="featuredChat" :source-status="featuredSourceStatus" />

    <!-- Nothing on any channel and nothing scheduled -->
    <div v-else class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-16 pb-10">
      <div class="max-w-2xl space-y-3">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300">
          {{ primaryChannel || 'Stage' }} &middot; off air
        </p>
        <h1 class="text-3xl sm:text-4xl font-bold text-white tracking-tight">Nothing on air right now</h1>
        <p class="text-primary-300">
          The archive is open below, and the programme guide has everything that is coming up.
        </p>
        <Link :href="route('schedule.index')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 hover:bg-primary-400 text-white text-sm font-semibold transition-colors">
          <FaCalendarIcon class="w-4 h-4" />
          Full schedule
        </Link>
      </div>
    </div>

    <!-- Filters: these replace the old stacked section headings, so a quiet day
         still reads as one deliberate grid instead of three empty sections. -->
    <div class="sticky top-14 z-30 bg-primary-900 border-b border-primary-800/50">
      <div
        role="tablist"
        aria-label="Filter shows"
        class="mx-auto max-w-page flex gap-2 overflow-x-auto px-4 sm:px-6 lg:px-8 py-3 scrollbar-none"
      >
        <button
          v-for="filter in filters"
          :key="filter.key"
          type="button"
          role="tab"
          :aria-selected="activeFilter === filter.key"
          class="filter-chip"
          :class="{ 'filter-chip-active': activeFilter === filter.key }"
          @click="activeFilter = filter.key"
        >
          <span v-if="filter.key === 'live'" class="w-1.5 h-1.5 rounded-full bg-red-500" aria-hidden="true" />
          {{ filter.label }}
          <span v-if="filter.count" class="tabular-nums opacity-60">{{ filter.count }}</span>
        </button>
      </div>
    </div>

    <!-- One grid for everything: live, upcoming and the archive -->
    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 py-6">
      <!-- One keyed child per item, so the group can track a tile across a filter
           switch or an Echo update. `--stagger` caps at 12 to keep the tail of a
           long grid from waiting on a delay nobody sees. -->
      <TransitionGroup v-if="visibleItems.length" tag="div" name="tile" appear class="stream-grid">
        <component
          :is="item.kind === 'archive' ? RecordingTile : ShowTile"
          v-for="(item, index) in visibleItems"
          :key="`${item.kind}-${item.id}`"
          v-bind="item.kind === 'archive' ? { recording: item.data } : { show: item.data }"
          :priority="index < 8"
          :style="{ '--stagger': Math.min(index, 12) }"
        />
      </TransitionGroup>

      <p v-else class="py-16 text-center text-primary-400">
        Nothing here right now.
      </p>

      <div v-if="showArchiveLink" class="mt-8 flex justify-center">
        <Link
          :href="route('recordings.index')"
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-primary-700 hover:border-primary-500 text-sm font-medium text-primary-200 transition-colors"
        >
          Browse all {{ archiveTotal }} archive shows
        </Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useRealtimeResync } from '@/composables/useRealtimeResync';
import StageHero from '@/Components/Shows/StageHero.vue';
import ShowTile from '@/Components/Shows/ShowTile.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import FaCalendarIcon from '@/Components/Icons/FaCalendarIcon.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  liveShows: { type: Array, default: () => [] },
  startingSoonShows: { type: Array, default: () => [] },
  upcomingShows: { type: Array, default: () => [] },
  archiveRecordings: { type: Array, default: () => [] },
  archiveTotal: { type: Number, default: 0 },
  featured: { type: Object, default: null },
  featuredChat: { type: Object, default: () => ({ source_id: null, messages: [] }) },
  primaryChannel: { type: String, default: null },
  channels: { type: Array, default: () => [] },
  currentTime: { type: String, required: false },
});

const liveShows = ref([...props.liveShows]);
const startingSoonShows = ref([...props.startingSoonShows]);
const upcomingShows = ref([...props.upcomingShows]);
const featured = ref(props.featured ? { ...props.featured } : null);
const featuredSourceStatus = ref(props.featured?.source_status ?? null);

const activeFilter = ref('all');

// The featured show already has the hero, so it is not repeated in the grid.
const gridLiveShows = computed(() =>
  liveShows.value.filter((show) => show.id !== featured.value?.id)
);

const items = computed(() => [
  ...gridLiveShows.value.map((show) => ({ kind: 'live', id: show.id, channel: show.source, data: show })),
  ...startingSoonShows.value.map((show) => ({ kind: 'soon', id: show.id, channel: show.source, data: show })),
  ...upcomingShows.value.map((show) => ({ kind: 'upcoming', id: show.id, channel: show.source, data: show })),
  ...props.archiveRecordings.map((recording) => ({ kind: 'archive', id: recording.id, channel: null, data: recording })),
]);

const countOf = (kind) => items.value.filter((item) => item.kind === kind).length;

const filters = computed(() => {
  const base = [
    { key: 'all', label: 'All' },
    { key: 'live', label: 'Live', count: countOf('live') + (featured.value?.status === 'live' ? 1 : 0) },
    { key: 'soon', label: 'Starting soon', count: countOf('soon') },
    { key: 'upcoming', label: 'Upcoming', count: countOf('upcoming') },
    { key: 'archive', label: 'Archive', count: props.archiveTotal || countOf('archive') },
  ].filter((filter) => filter.key === 'all' || filter.count > 0);

  const channelFilters = props.channels
    .filter((channel) => channel)
    .map((channel) => ({ key: `channel:${channel}`, label: channel }));

  return [...base, ...channelFilters];
});

const visibleItems = computed(() => {
  if (activeFilter.value === 'all') return items.value;

  if (activeFilter.value.startsWith('channel:')) {
    const channel = activeFilter.value.slice('channel:'.length);
    return items.value.filter((item) => item.channel === channel);
  }

  return items.value.filter((item) => item.kind === activeFilter.value);
});

const showArchiveLink = computed(() =>
  props.archiveTotal > props.archiveRecordings.length
  && ['all', 'archive'].includes(activeFilter.value)
);

// Echo keeps the page current; there is deliberately no periodic page reload,
// which used to interrupt anyone leaving the browse page open. What a websocket
// cannot do is replay what it missed, so pull fresh props back after a gap.
const resync = () => {
  router.reload({
    only: ['liveShows', 'startingSoonShows', 'upcomingShows', 'featured', 'featuredChat'],
  });
};

useRealtimeResync(resync);

watch(() => props.liveShows, (value) => liveShows.value = [...(value ?? [])]);
watch(() => props.startingSoonShows, (value) => startingSoonShows.value = [...(value ?? [])]);
watch(() => props.upcomingShows, (value) => upcomingShows.value = [...(value ?? [])]);
watch(() => props.featured, (value) => {
  featured.value = value ? { ...value } : null;
  featuredSourceStatus.value = value?.source_status ?? null;
  subscribeToFeaturedSource(featured.value?.source_id);
}, { deep: true });

// The hero plays the featured channel, so it needs that source's status the same
// way the full player does: a stream that stops is not a stream still connecting.
let featuredSourceId = null;

const subscribeToFeaturedSource = (sourceId) => {
  if (sourceId === featuredSourceId) return;

  if (featuredSourceId) Echo.leave(`source.${featuredSourceId}`);
  featuredSourceId = sourceId ?? null;

  if (!featuredSourceId) return;

  Echo.channel(`source.${featuredSourceId}`).listen('.source.status.changed', (e) => {
    featuredSourceStatus.value = e.status;

    // Coming back on air means a new playlist to attach to, which only the server
    // can hand out.
    if (e.status === 'online') resync();
  });
};

onMounted(() => {
  subscribeToFeaturedSource(featured.value?.source_id);

  Echo.channel('shows')
    .listen('.show.status.changed', (e) => {
      if (featured.value?.id === e.show.id) {
        featured.value = {
          ...featured.value,
          ...e.show,
          slug: e.show.slug || featured.value.slug,
          hls_url: e.hlsUrl ?? e.show.hls_url ?? featured.value.hls_url,
        };
      }

      if (e.status === 'live') {
        removeById(upcomingShows, e.show.id);
        removeById(startingSoonShows, e.show.id);

        if (!liveShows.value.some((show) => show.id === e.show.id)) {
          liveShows.value.unshift(e.show);
        }
      }

      if (e.status === 'ended') {
        removeById(liveShows, e.show.id);
        removeById(startingSoonShows, e.show.id);
      }
    })
    .listen('.show.viewer.count', (e) => {
      const show = liveShows.value.find((s) => s.id === e.show_id);
      if (show) {
        show.viewer_count = e.viewer_count;
      }
      if (featured.value?.id === e.show_id) {
        featured.value.viewer_count = e.viewer_count;
      }
    })
    .listen('.thumbnail.updated', (e) => {
      [liveShows, startingSoonShows, upcomingShows].forEach((list) => {
        const show = list.value.find((s) => s.id === e.show_id);
        if (show) {
          show.thumbnail_url = e.thumbnail_url;
        }
      });

      if (featured.value?.id === e.show_id) {
        featured.value.thumbnail_url = e.thumbnail_url;
      }
    });
});

const removeById = (list, id) => {
  const index = list.value.findIndex((show) => show.id === id);
  if (index !== -1) {
    list.value.splice(index, 1);
  }
};

onUnmounted(() => {
  Echo.leave('shows');

  if (featuredSourceId) {
    Echo.leave(`source.${featuredSourceId}`);
    featuredSourceId = null;
  }
});
</script>

<style scoped>
@reference "../../css/app.css";

.stream-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 1.25rem 1rem;
}

.filter-chip {
  @apply inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-primary-700/70 px-3.5 py-1.5 text-sm font-medium text-primary-200 transition-colors;
}

/* :not() keeps hover from repainting the selected chip white-on-white. */
.filter-chip:hover:not(.filter-chip-active) {
  @apply border-primary-500 text-white;
}

.filter-chip-active {
  @apply bg-white text-primary-950 border-white font-semibold;
}

.filter-chip:focus-visible {
  @apply outline-none ring-2 ring-primary-400 ring-offset-2 ring-offset-primary-900;
}

.scrollbar-none {
  scrollbar-width: none;
}

.scrollbar-none::-webkit-scrollbar {
  display: none;
}

</style>
