<script setup>
/**
 * Renders the server-declared filter set plus the search box. Every control writes to the
 * query string, so a filtered view is linkable and a poll cannot reset it.
 */
import { ref, watch } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { useTableQuery } from './useTableQuery.js';

const props = defineProps({
  filters: { type: Array, default: () => [] },
  search: { type: String, default: '' },
  searchable: { type: Boolean, default: true },
});

const { setFilter, setSearch } = useTableQuery();

const term = ref(props.search);
const open = ref(null);
let debounce = null;

/** Which multi-select popover is open, and what its button reads when closed. */
const summary = (filter) => {
  const selected = filter.value ?? [];

  if (selected.length === 0) {
    return null;
  }

  if (selected.length === 1) {
    return filter.options?.find((option) => option.value === selected[0])?.label ?? selected[0];
  }

  return `${selected.length} selected`;
};

const toggleValue = (filter, value) => {
  const selected = filter.value ?? [];

  setFilter(
    filter.key,
    selected.includes(value) ? selected.filter((item) => item !== value) : [...selected, value],
  );
};

watch(
  () => props.search,
  (value) => {
    term.value = value;
  },
);

const onSearch = () => {
  window.clearTimeout(debounce);
  debounce = window.setTimeout(() => setSearch(term.value), 300);
};

const control =
  'h-7 rounded border border-hairline bg-surface-2 px-2 text-[12px] text-fg-1 outline-none focus:border-state-live/50';
</script>

<template>
  <div class="relative flex h-11 flex-wrap items-center gap-2 border-b border-hairline bg-surface-1 px-3">
    <div v-if="open" class="fixed inset-0 z-20" aria-hidden="true" @click="open = null" />

    <template v-for="filter in filters" :key="filter.key">
      <select
        v-if="filter.type === 'select' && !filter.multiple"
        :value="filter.value"
        :class="control"
        @change="setFilter(filter.key, $event.target.value)"
      >
        <option value="">{{ filter.placeholder ?? `All ${filter.label.toLowerCase()}` }}</option>
        <option v-for="option in filter.options" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>

      <!-- Multi-select is a checkbox popover, not a native `select multiple`: the native
           one renders in the OS light palette, cannot be restyled, and clips its rows to
           the control height. -->
      <div v-else-if="filter.type === 'select'" class="relative">
        <button
          type="button"
          :class="[control, 'inline-flex items-center gap-1.5']"
          :aria-expanded="open === filter.key"
          @click="open = open === filter.key ? null : filter.key"
        >
          <span class="text-[11px] uppercase tracking-wide text-fg-3">{{ filter.label }}</span>
          <span :class="summary(filter) ? 'text-fg-1' : 'text-fg-3'">{{ summary(filter) || 'any' }}</span>
          <ManageIcon name="chevron-down" :size="12" class="text-fg-3" />
        </button>

        <div
          v-if="open === filter.key"
          class="absolute top-8 left-0 z-30 w-48 rounded border border-hairline bg-surface-2 p-1.5 shadow-lg"
        >
          <label
            v-for="option in filter.options"
            :key="option.value"
            class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-[12px] text-fg-1 hover:bg-surface-3"
          >
            <input
              type="checkbox"
              class="accent-state-live"
              :checked="(filter.value ?? []).includes(option.value)"
              @change="toggleValue(filter, option.value)"
            />
            {{ option.label }}
          </label>

          <button
            type="button"
            class="mt-1 w-full rounded px-1.5 py-1 text-left text-[11px] text-fg-3 transition-colors hover:bg-surface-3 hover:text-fg-1"
            @click="setFilter(filter.key, [])"
          >
            Clear
          </button>
        </div>
      </div>

      <select
        v-else-if="filter.type === 'ternary'"
        :value="filter.value"
        :class="control"
        @change="setFilter(filter.key, $event.target.value)"
      >
        <option value="">{{ filter.placeholder ?? `All ${filter.label.toLowerCase()}` }}</option>
        <option value="1">{{ filter.trueLabel ?? 'Yes' }}</option>
        <option value="0">{{ filter.falseLabel ?? 'No' }}</option>
      </select>

      <label
        v-else
        class="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded border border-hairline px-2 text-[12px] transition-colors"
        :class="filter.value ? 'border-state-live/40 bg-state-live/10 text-state-live' : 'text-fg-2 hover:bg-surface-3'"
      >
        <input
          type="checkbox"
          class="accent-state-live"
          :checked="filter.value"
          @change="setFilter(filter.key, $event.target.checked)"
        />
        {{ filter.label }}
      </label>
    </template>

    <label v-if="searchable" class="ml-auto flex items-center gap-1.5">
      <ManageIcon name="search" :size="13" class="text-fg-3" />
      <input
        v-model="term"
        type="search"
        placeholder="Search"
        :class="[control, 'w-48']"
        @input="onSearch"
      />
    </label>
  </div>
</template>
