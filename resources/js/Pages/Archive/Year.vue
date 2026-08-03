<template>
  <div class="min-h-screen">
    <Head :title="`Archive ${year}`" />

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-8 pb-4">
      <Link :href="route('recordings.index')" class="back-link">
        <FaArrowLeftIcon class="h-4 w-4" />
        All collections
      </Link>

      <div class="mt-4 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-2">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300">Collection</p>
          <h1 class="text-4xl font-bold tracking-tight text-white tabular-nums">{{ year }}</h1>
          <p class="text-sm text-primary-300 tabular-nums">
            {{ publishedCount }} {{ publishedCount === 1 ? 'recording' : 'recordings' }}
            <span aria-hidden="true">·</span> {{ hours }}h runtime
            <span aria-hidden="true">·</span> {{ formatViews(totalViews) }} views
          </p>
        </div>

        <form class="w-full sm:w-72" @submit.prevent="submitSearch">
          <label class="sr-only" :for="`year-search-${year}`">Search {{ year }}</label>
          <input
            :id="`year-search-${year}`"
            v-model="searchQuery"
            type="search"
            :placeholder="`Search ${year}`"
            class="search-input"
          />
        </form>
      </div>

      <!-- Jump between years without going back to the index -->
      <div v-if="years.length > 1" class="mt-6 flex gap-2 overflow-x-auto scrollbar-none">
        <Link
          v-for="option in years"
          :key="option"
          :href="route('recordings.year', option)"
          class="year-chip tabular-nums"
          :class="{ 'year-chip-active': option === year }"
        >
          {{ option }}
        </Link>
      </div>
    </div>

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pb-14">
      <TransitionGroup v-if="recordings.length" tag="div" name="tile" appear class="stream-grid">
        <RecordingTile
          v-for="(recording, index) in recordings"
          :key="recording.id"
          :recording="recording"
          :priority="index < 8"
          :style="{ '--stagger': Math.min(index, 12) }"
        />
      </TransitionGroup>

      <p v-else class="py-16 text-center text-primary-400">
        {{ search ? `Nothing in ${year} matches that search.` : `Nothing published for ${year} yet.` }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import FaArrowLeftIcon from '@/Components/Icons/FaArrowLeftIcon.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  year: { type: Number, required: true },
  years: { type: Array, default: () => [] },
  // Published recordings plus, mixed in by date, the shows that ended but have not
  // been published yet (flagged `is_pending`, rendered as dimmed tiles).
  recordings: { type: Array, default: () => [] },
  totalViews: { type: Number, default: 0 },
  hours: { type: Number, default: 0 },
  search: { type: String, default: null },
});

const searchQuery = ref(props.search ?? '');

const publishedCount = computed(() => props.recordings.filter((recording) => !recording.is_pending).length);

const submitSearch = () => {
  router.get(
    route('recordings.year', props.year),
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

.stream-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 1.25rem 1rem;
}

.back-link {
  @apply inline-flex items-center gap-2 text-sm text-primary-300 transition-colors hover:text-white;
}

.year-chip {
  @apply inline-flex items-center whitespace-nowrap rounded-full border border-primary-700/70 px-4 py-1.5 text-sm font-medium text-primary-200 transition-colors;
}

.year-chip:hover:not(.year-chip-active) {
  @apply border-primary-500 text-white;
}

.year-chip-active {
  @apply border-white bg-white font-semibold text-primary-950;
}

.year-chip:focus-visible,
.back-link:focus-visible {
  @apply outline-none ring-2 ring-primary-400 ring-offset-2 ring-offset-primary-900;
}

.search-input {
  @apply w-full rounded-full border border-primary-700 bg-primary-950/60 px-4 py-2 text-sm text-white placeholder:text-primary-500 transition-colors;
}

.search-input:focus {
  @apply border-primary-400 outline-none ring-2 ring-primary-500/30;
}

.scrollbar-none {
  scrollbar-width: none;
}

.scrollbar-none::-webkit-scrollbar {
  display: none;
}
</style>
