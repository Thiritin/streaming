<script setup>
/**
 * Renders the list envelope produced by App\Support\Manage\Table.
 *
 * Everything domain-specific (labels, tones, formatting, which actions exist) arrives
 * from the server. This component only knows the cell types documented on
 * App\Support\Manage\Column, and it draws them two ways from the same data: a row per
 * record from md up, a card per record below it.
 *
 * A table narrower than its columns is a sideways scroll with the identifying column
 * scrolled off, so a phone gets cards instead: the leading column is the card's heading,
 * a badge sits beside it, and every other column becomes a labelled line.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionButton from './ActionButton.vue';
import InlineCell from './InlineCell.vue';
import ManageIcon from './ManageIcon.vue';
import TableCell from './TableCell.vue';
import { useInlineEdit } from './useInlineEdit.js';
import { useTableQuery } from './useTableQuery.js';

const props = defineProps({
  table: { type: Object, required: true },
});

const { toggleSort } = useTableQuery();
const { isEnabled: inlineEditing } = useInlineEdit(() => props.table.name);

/*
 * Inline editing, when the operator has switched it on: a cell whose column key matches
 * a field the server declared on that row renders the control instead of the value, and
 * every change is saved on its own. There is no form and no submit, so nothing can be
 * left half-entered - and nothing is offered that the server did not declare, which is
 * where the permission check lives.
 */
const drafts = reactive({});
const states = reactive({});

const fieldKey = (row, field) => `${row.id}:${field.key}`;

const inlineField = (row, key) =>
  inlineEditing.value ? (row.inline?.fields ?? []).find((field) => field.key === key) ?? null : null;

const draftValue = (row, field) => drafts[fieldKey(row, field)] ?? field.value ?? '';

const save = (row, field, value) => {
  const key = fieldKey(row, field);

  if (String(value) === String(field.value ?? '')) {
    return;
  }

  drafts[key] = value;
  states[key] = 'saving';

  // No `only`: the flashed toast rides on the shared props, and a partial reload of the
  // table alone would drop it.
  router.patch(
    row.inline.url,
    { [field.field ?? field.key]: value },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        // The reloaded row carries the saved value, so the draft has nothing left to say.
        delete drafts[key];
        states[key] = 'saved';
        window.setTimeout(() => {
          if (states[key] === 'saved') delete states[key];
        }, 1500);
      },
      // The draft stays put on a refusal, so the operator can see and fix what they typed.
      onError: () => (states[key] = 'error'),
    },
  );
};

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

const hasActions = computed(() => props.table.rows.some((row) => row.actions.length));

const allSelected = computed(
  () => props.table.rows.length > 0 && selected.value.length === props.table.rows.length,
);

const toggleAll = () => {
  selected.value = allSelected.value ? [] : props.table.rows.map((row) => row.id);
};

/*
 * The card layout, derived rather than declared: a table already puts what identifies a
 * record first, so the leading text column is the heading, a leading thumbnail is the
 * card's image, and the first badge is the state chip beside the heading. Whatever is
 * left is a labelled line under it.
 */
const cardImage = computed(() => visibleColumns.value.find((column) => column.type === 'image') ?? null);

const cardTitle = computed(
  () =>
    visibleColumns.value.find((column) => ['text', 'copyable'].includes(column.type))
      ?? visibleColumns.value.find((column) => !['image', 'badge'].includes(column.type))
      ?? null,
);

// State first when the table has one, since that is what a card is scanned for.
const cardBadge = computed(() => {
  const badges = visibleColumns.value.filter((column) => column.type === 'badge');

  return badges.find((column) => column.key === 'status') ?? badges[0] ?? null;
});

const cardFields = computed(() =>
  visibleColumns.value.filter(
    (column) => ![cardImage.value?.key, cardTitle.value?.key, cardBadge.value?.key].includes(column.key),
  ),
);

const align = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

const cell = (row, key) => row.cells?.[key] ?? null;

const isEmpty = (value) => value === null || value === undefined || value === '';

/** An empty line is dropped from a card; on a phone the height costs more than the symmetry buys. */
const showsOnCard = (row, column) =>
  Boolean(inlineField(row, column.key))
  || ['bool', 'toggle', 'badge', 'icon', 'number'].includes(column.type)
  || !isEmpty(cell(row, column.key));

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

    <!-- Cards, below md. -->
    <div class="flex flex-col gap-2 p-2 md:hidden">
      <article
        v-for="row in table.rows"
        :key="`card-${row.id}`"
        class="rounded-lg border border-hairline bg-surface-1 transition-colors active:bg-surface-2"
        :class="row.url ? 'cursor-pointer' : ''"
        @click="open(row, $event)"
      >
        <div class="flex items-start gap-2.5 p-3">
          <input
            v-if="table.bulkActions.length"
            v-model="selected"
            type="checkbox"
            :value="row.id"
            class="mt-0.5 size-4 shrink-0 accent-state-live"
            :aria-label="`Select row ${row.id}`"
          />

          <TableCell
            v-if="cardImage && !isEmpty(cell(row, cardImage.key))"
            :column="cardImage"
            :value="cell(row, cardImage.key)"
            image-class="h-10 w-16 shrink-0"
          />

          <div class="min-w-0 flex-1">
            <div v-if="cardTitle" class="text-[15px] font-medium text-fg-1">
              <InlineCell
                v-if="inlineField(row, cardTitle.key)"
                :field="inlineField(row, cardTitle.key)"
                :value="draftValue(row, inlineField(row, cardTitle.key))"
                :state="states[fieldKey(row, inlineField(row, cardTitle.key))]"
                @save="save(row, inlineField(row, cardTitle.key), $event)"
              />
              <TableCell v-else :column="cardTitle" :value="cell(row, cardTitle.key)" />
            </div>
          </div>

          <TableCell
            v-if="cardBadge"
            :column="cardBadge"
            :value="cell(row, cardBadge.key)"
            class="shrink-0"
          />
        </div>

        <dl
          v-if="cardFields.some((column) => showsOnCard(row, column))"
          class="flex flex-col gap-1 border-t border-hairline/60 px-3 py-2 text-[13px]"
        >
          <div
            v-for="column in cardFields"
            :key="column.key"
            v-show="showsOnCard(row, column)"
            class="flex items-center gap-3"
          >
            <dt class="shrink-0 text-[11px] uppercase tracking-wide text-fg-3">{{ column.label }}</dt>
            <dd class="ml-auto min-w-0 truncate text-right text-fg-1" :class="column.type === 'number' ? 'tabular-nums' : ''">
              <InlineCell
                v-if="inlineField(row, column.key)"
                :field="inlineField(row, column.key)"
                :value="draftValue(row, inlineField(row, column.key))"
                :state="states[fieldKey(row, inlineField(row, column.key))]"
                @save="save(row, inlineField(row, column.key), $event)"
              />
              <TableCell v-else :column="column" :value="cell(row, column.key)" />
            </dd>
          </div>
        </dl>

        <div
          v-if="row.actions.length"
          class="flex flex-wrap items-center gap-1.5 border-t border-hairline/60 px-3 py-2"
        >
          <ActionButton v-for="action in row.actions" :key="action.name" :action="action" />
        </div>
      </article>

      <p v-if="!table.rows.length" class="py-10 text-center text-[13px] text-fg-3">
        Nothing matches the current filters.
      </p>
    </div>

    <!-- Rows, from md up. -->
    <div class="hidden overflow-x-auto md:block">
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
            <th v-if="hasActions" class="px-3" />
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
              <InlineCell
                v-if="inlineField(row, column.key)"
                :field="inlineField(row, column.key)"
                :value="draftValue(row, inlineField(row, column.key))"
                :state="states[fieldKey(row, inlineField(row, column.key))]"
                @save="save(row, inlineField(row, column.key), $event)"
              />
              <TableCell v-else :column="column" :value="cell(row, column.key)" />
            </td>

            <td v-if="hasActions" class="px-3">
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
