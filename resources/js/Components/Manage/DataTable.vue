<script setup>
/**
 * Renders the list envelope produced by App\Support\Manage\Table.
 *
 * Everything domain-specific (labels, tones, formatting, which actions exist) arrives
 * from the server. This component only knows the cell types documented on
 * App\Support\Manage\Column.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionButton from './ActionButton.vue';
import ManageIcon from './ManageIcon.vue';
import StatusBadge from './StatusBadge.vue';
import { resolve, toneText } from './tones.js';
import { useTableQuery } from './useTableQuery.js';

const props = defineProps({
  table: { type: Object, required: true },
});

const { toggleSort } = useTableQuery();

const selected = ref([]);

// A reload can drop rows out from under the selection; keep only what is still visible.
watch(
  () => props.table.rows,
  (rows) => {
    const ids = rows.map((row) => row.id);
    selected.value = selected.value.filter((id) => ids.includes(id));
  },
);

const visibleColumns = computed(() => {
  const hidden = props.table.hiddenColumns ?? [];

  return props.table.columns.filter((column) => !hidden.includes(column.key));
});

const allSelected = computed(
  () => props.table.rows.length > 0 && selected.value.length === props.table.rows.length,
);

const toggleAll = () => {
  selected.value = allSelected.value ? [] : props.table.rows.map((row) => row.id);
};

const align = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

const cell = (row, key) => row.cells?.[key] ?? null;

const isEmpty = (value) => value === null || value === undefined || value === '';

const numberDisplay = (value) => {
  if (isEmpty(value)) {
    return null;
  }

  if (typeof value === 'object') {
    return value.display ?? null;
  }

  // A non-numeric value would format as the literal "NaN", which reads as broken
  // data rather than an empty cell, so it falls through to the placeholder.
  if (Number.isNaN(Number(value))) {
    return null;
  }

  return new Intl.NumberFormat('en-GB').format(value).replace(/,/g, ' ');
};

const copy = async (value) => {
  try {
    await navigator.clipboard.writeText(String(value));
  } catch {
    // Clipboard is unavailable outside a secure context; nothing to recover from.
  }
};

const open = (row, event) => {
  if (!row.url || event.target.closest('a, button, input, select, label')) {
    return;
  }

  router.visit(row.url);
};
</script>

<template>
  <div class="flex min-w-0 flex-col">
    <!-- Bulk bar: only present while something is selected, so it never costs vertical space. -->
    <div
      v-if="selected.length && table.bulkActions.length"
      class="flex min-h-10 flex-wrap items-center gap-2 border-b border-hairline bg-surface-2 px-3 py-1.5"
    >
      <span class="text-[12px] text-fg-2">{{ selected.length }} selected</span>
      <ActionButton
        v-for="action in table.bulkActions"
        :key="action.name"
        :action="action"
        :data="{ ids: selected }"
      />
      <button
        type="button"
        class="ml-auto text-[12px] text-fg-3 transition-colors hover:text-fg-1"
        @click="selected = []"
      >
        Clear
      </button>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-[13px]">
        <thead>
          <tr class="border-y border-hairline bg-surface-2">
            <th v-if="table.bulkActions.length" class="w-8 px-3">
              <input
                type="checkbox"
                :checked="allSelected"
                class="accent-state-live"
                aria-label="Select all rows"
                @change="toggleAll"
              />
            </th>
            <th
              v-for="column in visibleColumns"
              :key="column.key"
              class="h-7 px-3 text-[11px] font-medium uppercase tracking-wide text-fg-2"
              :class="align[column.align] ?? align.left"
              :style="column.width ? { width: column.width } : null"
            >
              <button
                v-if="column.sortable"
                type="button"
                class="inline-flex items-center gap-1 transition-colors hover:text-fg-1"
                @click="toggleSort(column, table.sort)"
              >
                {{ column.label }}
                <ManageIcon
                  v-if="table.sort?.key === column.key"
                  :name="table.sort.dir === 'asc' ? 'chevron-up' : 'chevron-down'"
                  :size="12"
                />
              </button>
              <span v-else>{{ column.label }}</span>
            </th>
            <th v-if="table.rows.some((row) => row.actions.length)" class="px-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="row in table.rows"
            :key="row.id"
            class="border-b border-hairline/60 transition-colors hover:bg-surface-2"
            :class="row.url ? 'cursor-pointer' : ''"
            @click="open(row, $event)"
          >
            <td v-if="table.bulkActions.length" class="px-3">
              <input
                v-model="selected"
                type="checkbox"
                :value="row.id"
                class="accent-state-live"
                :aria-label="`Select row ${row.id}`"
              />
            </td>

            <td
              v-for="column in visibleColumns"
              :key="column.key"
              class="h-8 px-3 whitespace-nowrap text-fg-1"
              :class="[align[column.align] ?? align.left, column.type === 'number' ? 'tabular-nums' : '']"
            >
              <template v-if="column.type === 'badge'">
                <StatusBadge :status="cell(row, column.key)" />
              </template>

              <template v-else-if="column.type === 'number'">
                <span v-if="numberDisplay(cell(row, column.key)) !== null">
                  {{ numberDisplay(cell(row, column.key)) }}
                  <span
                    v-if="cell(row, column.key)?.description"
                    class="block text-[11px] text-fg-3"
                  >{{ cell(row, column.key).description }}</span>
                </span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <template v-else-if="column.type === 'image'">
                <img
                  v-if="!isEmpty(cell(row, column.key))"
                  :src="cell(row, column.key)"
                  alt=""
                  class="h-6 w-10 rounded-sm border border-hairline object-cover"
                />
                <span v-else class="text-fg-3">—</span>
              </template>

              <template v-else-if="column.type === 'bool'">
                <ManageIcon
                  :name="cell(row, column.key) ? 'circle-check' : 'circle-x'"
                  :size="15"
                  :class="cell(row, column.key) ? 'inline text-state-ok' : 'inline text-fg-3'"
                />
              </template>

              <template v-else-if="column.type === 'icon'">
                <ManageIcon
                  :name="cell(row, column.key)?.icon"
                  :size="15"
                  class="inline"
                  :class="resolve(toneText, cell(row, column.key)?.tone)"
                  :title="cell(row, column.key)?.title"
                />
              </template>

              <template v-else-if="column.type === 'color'">
                <span class="inline-flex items-center gap-1.5">
                  <span
                    class="inline-block size-3.5 rounded-sm border border-hairline"
                    :style="{ backgroundColor: cell(row, column.key) }"
                  />
                  <span class="font-mono text-[12px] text-fg-2">{{ cell(row, column.key) }}</span>
                </span>
              </template>

              <template v-else-if="column.type === 'copyable'">
                <button
                  v-if="!isEmpty(cell(row, column.key))"
                  type="button"
                  class="group inline-flex items-center gap-1 font-mono text-[12px] text-fg-1"
                  :title="`Copy ${cell(row, column.key)}`"
                  @click.stop="copy(cell(row, column.key))"
                >
                  {{ cell(row, column.key) }}
                  <ManageIcon name="copy" :size="12" class="opacity-0 transition-opacity group-hover:opacity-60" />
                </button>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <template v-else-if="column.type === 'toggle'">
                <input
                  type="checkbox"
                  class="accent-state-live"
                  :checked="cell(row, column.key)?.value"
                  :aria-label="column.label"
                  @click.stop
                  @change="router.post(cell(row, column.key).url, {}, { preserveScroll: true })"
                />
              </template>

              <template v-else-if="column.type === 'datetime'">
                <span
                  v-if="!isEmpty(cell(row, column.key))"
                  class="tabular-nums"
                  :title="cell(row, column.key)?.title"
                >{{ cell(row, column.key)?.display ?? cell(row, column.key) }}</span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>

              <template v-else>
                <span v-if="!isEmpty(cell(row, column.key))">{{ cell(row, column.key) }}</span>
                <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
              </template>
            </td>

            <td v-if="table.rows.some((r) => r.actions.length)" class="px-3">
              <div class="flex justify-end gap-1">
                <ActionButton
                  v-for="action in row.actions"
                  :key="action.name"
                  :action="action"
                  icon-only
                />
              </div>
            </td>
          </tr>

          <tr v-if="!table.rows.length">
            <td :colspan="visibleColumns.length + 2" class="h-24 text-center text-[13px] text-fg-3">
              Nothing matches the current filters.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
