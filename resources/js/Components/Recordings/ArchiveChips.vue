<template>
  <div class="chip-bar" role="group" aria-label="Filter the archive">
    <button
      type="button"
      class="chip"
      :class="{ 'chip-active': !filters.event && !filters.year && !filters.source && !filters.category }"
      @click="$emit('select', { event: null, year: null, source: null, category: null })"
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

    <span v-if="chips.categories.length && chips.collections.length" class="chip-divider" aria-hidden="true" />

    <!-- One chip per run of the convention. A recording filed under no run keeps a
         year chip instead, which is why these are one row and not two: they answer
         the same question and only one of them can apply to a given recording. -->
    <button
      v-for="collection in chips.collections"
      :key="collection.key"
      type="button"
      class="chip"
      :class="{
        'chip-active': isActive(collection),
        'tabular-nums': collection.year !== null,
      }"
      @click="$emit('select', select(collection))"
    >
      {{ collection.label }}
    </button>

    <span v-if="chips.collections.length && chips.sources.length" class="chip-divider" aria-hidden="true" />

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
const props = defineProps({
  chips: { type: Object, required: true },
  filters: { type: Object, required: true },
});

defineEmits(['select']);

const isActive = (collection) =>
  collection.event !== null
    ? props.filters.event === collection.event
    : props.filters.year === collection.year;

// Events and years are the same axis, so picking either clears the other. Picking
// the one already on clears it, which is how a chip toggles off.
const select = (collection) =>
  isActive(collection)
    ? { event: null, year: null }
    : { event: collection.event, year: collection.year };
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

/* On a phone the row scrolls out of the page gutter, so the first chip sits on
   the same line as the heading above it but a scrolled chip runs off the edge. */
@media (max-width: 640px) {
  .chip-bar {
    padding-left: 1rem;
    margin-left: -1rem;
  }
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
