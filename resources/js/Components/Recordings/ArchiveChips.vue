<template>
  <div class="chip-bar" role="group" aria-label="Filter the archive">
    <button
      type="button"
      class="chip"
      :class="{ 'chip-active': !filters.year && !filters.source }"
      @click="$emit('select', { year: null, source: null, category: null })"
    >
      All
    </button>

    <button
      v-for="category in chips.categories"
      :key="`category-${category.slug}`"
      type="button"
      class="chip"
      :class="{ 'chip-active': filters.category === category.slug }"
      @click="$emit('select', { category: filters.category === category.slug ? null : category.slug })"
    >
      {{ category.name }}
    </button>

    <span v-if="chips.categories.length && chips.years.length" class="chip-divider" aria-hidden="true" />

    <button
      v-for="year in chips.years"
      :key="`year-${year.year}`"
      type="button"
      class="chip tabular-nums"
      :class="{ 'chip-active': filters.year === year.year }"
      @click="$emit('select', { year: filters.year === year.year ? null : year.year })"
    >
      {{ year.year }}
    </button>

    <span v-if="chips.years.length && chips.sources.length" class="chip-divider" aria-hidden="true" />

    <button
      v-for="source in chips.sources"
      :key="`source-${source.slug}`"
      type="button"
      class="chip"
      :class="{ 'chip-active': filters.source === source.slug }"
      @click="$emit('select', { source: filters.source === source.slug ? null : source.slug })"
    >
      {{ source.name }}
    </button>
  </div>
</template>

<script setup>
defineProps({
  chips: { type: Object, required: true },
  filters: { type: Object, required: true },
});

defineEmits(['select']);
</script>

<style scoped>
@reference "../../../css/app.css";

.chip-bar {
  @apply flex items-center gap-2 overflow-x-auto py-1;
  scrollbar-width: none;
}

.chip-bar::-webkit-scrollbar {
  display: none;
}

.chip {
  @apply inline-flex shrink-0 items-center whitespace-nowrap rounded-lg bg-white/8 px-3 py-1.5 text-sm font-medium text-primary-100 transition-colors;
}

.chip:hover:not(.chip-active) {
  @apply bg-white/15 text-white;
}

.chip-active {
  @apply bg-white font-semibold text-primary-950;
}

.chip:focus-visible {
  @apply outline-none ring-2 ring-primary-400;
}

.chip-divider {
  @apply h-5 w-px shrink-0 bg-white/15;
}
</style>
