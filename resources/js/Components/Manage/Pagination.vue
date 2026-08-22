<script setup>
import { computed } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { useTableQuery } from './useTableQuery.js';

const props = defineProps({
  meta: { type: Object, required: true },
});

const { setPage, setPerPage } = useTableQuery();

// A short window around the current page, so long lists do not grow a wall of links.
const pages = computed(() => {
  const { page, lastPage } = props.meta;
  const from = Math.max(1, page - 2);
  const to = Math.min(lastPage, from + 4);

  return Array.from({ length: to - from + 1 }, (_, index) => from + index);
});
</script>

<template>
  <div class="flex min-h-10 shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-t border-hairline px-3 py-1.5 text-[12px] text-fg-2 md:flex-nowrap md:py-0">
    <span class="tabular-nums">
      <template v-if="meta.total">{{ meta.from }}–{{ meta.to }} of {{ meta.total }}</template>
      <template v-else>0 results</template>
    </span>

    <label class="flex items-center gap-1.5">
      <span class="text-fg-3">per page</span>
      <select
        :value="meta.perPage"
        class="h-8 rounded border border-hairline bg-surface-2 px-1 text-[12px] text-fg-1 md:h-6"
        @change="setPerPage(Number($event.target.value))"
      >
        <option v-for="option in meta.perPageOptions" :key="option" :value="option">{{ option }}</option>
      </select>
    </label>

    <div v-if="meta.lastPage > 1" class="flex w-full items-center gap-1 md:ml-auto md:w-auto">
      <button
        type="button"
        class="inline-flex size-8 items-center justify-center rounded border border-hairline disabled:opacity-30 md:size-6"
        :disabled="meta.page <= 1"
        aria-label="Previous page"
        @click="setPage(meta.page - 1)"
      >
        <ManageIcon name="chevron-left" :size="13" />
      </button>

      <button
        v-for="page in pages"
        :key="page"
        type="button"
        class="inline-flex h-8 min-w-8 flex-1 items-center justify-center rounded border px-1 tabular-nums transition-colors md:h-6 md:min-w-6 md:flex-none"
        :class="page === meta.page ? 'border-state-live/40 bg-state-live/10 text-state-live' : 'border-hairline hover:bg-surface-3'"
        @click="setPage(page)"
      >
        {{ page }}
      </button>

      <button
        type="button"
        class="inline-flex size-8 items-center justify-center rounded border border-hairline disabled:opacity-30 md:size-6"
        :disabled="meta.page >= meta.lastPage"
        aria-label="Next page"
        @click="setPage(meta.page + 1)"
      >
        <ManageIcon name="chevron-right" :size="13" />
      </button>
    </div>
  </div>
</template>
