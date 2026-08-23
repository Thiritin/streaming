<script setup>
/**
 * The recording plan: every show on one screen, every cell editable where it stands.
 *
 * Not a DataTable. That component is built around reading a record and opening it, with
 * editing as a mode you switch on for a minute; this is the opposite shape. The work here
 * is dividing a few hundred slots between people and then accounting for what came back,
 * which is read down a column and typed across a row, so there is no inline-edit switch,
 * no pagination and no row click that navigates away.
 *
 * Each cell saves on its own the moment it changes, the same as inline editing elsewhere:
 * there is no form and no submit, so a half-filled row cannot sit unsaved next to a
 * finished one.
 *
 * `table-fixed` rather than the browser's own column sizing: the first two columns are
 * pinned while the rest scroll under them, and a pinned column whose width the browser
 * may reconsider on the next reload lands its neighbour underneath itself.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';
import { resolve, toneBadge, toneText } from '@/Components/Manage/tones.js';

const props = defineProps({
  rows: { type: Array, required: true },
  summary: { type: Array, required: true },
  filters: { type: Object, required: true },
  options: { type: Object, required: true },
  urls: { type: Object, required: true },
  defaults: { type: Object, required: true },
  me: { type: Object, required: true },
  can_edit: { type: Boolean, default: false },
  truncated: { type: Boolean, default: false },
  limit: { type: Number, default: 0 },
});

const page = usePage();

/* ---------------------------------------------------------------- filters */

const search = ref(props.filters.search ?? '');
let debounce = null;

const visit = (params) => {
  router.get(page.url.split('?')[0], params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

/** Whether a filter is set, as opposed to merely sitting at its default. */
const isDefault = (key, value) =>
  value === null || value === '' || value === false || value === props.defaults[key];

/**
 * The whole filter set as query params, with anything at its default left out - so the
 * URL carries what was actually chosen and a link stays readable.
 */
const query = (overrides = {}) => {
  const merged = { ...props.filters, search: search.value, ...overrides };
  const params = {};

  Object.entries(merged).forEach(([key, value]) => {
    if (! isDefault(key, value)) {
      params[key] = value === true ? 1 : value;
    }
  });

  return params;
};

const setFilter = (key, value) => visit(query({ [key]: value || null }));

/** A summary tile is a shortcut to the rows it counted; clicking it again undoes that. */
const applyTile = (tile) => {
  if (!tile.filter) {
    return;
  }

  const [key, value] = tile.filter;

  setFilter(key, props.filters[key] === value ? null : value);
};

watch(search, () => {
  window.clearTimeout(debounce);
  debounce = window.setTimeout(() => visit(query()), 300);
});

const activeFilters = computed(
  () => Object.entries(props.filters).filter(([key, value]) => ! isDefault(key, value)).length,
);

const clearFilters = () => {
  search.value = '';
  visit({});
};

/* ------------------------------------------------------------------ cells */

/** Which prop of a row each editable field reads its current value from. */
const FIELDS = {
  publish_plan: 'publish_plan',
  recording_owner_id: 'owner_id',
  stream_condition: 'stream_condition',
  onsite_status: 'onsite_status',
  recording_note: 'note',
};

/** Unsaved value per cell, so a refusal leaves what was typed on screen. */
const drafts = reactive({});
/** null, 'saving', 'saved' or 'error'. Drives the cell's border only. */
const states = reactive({});

const cellKey = (row, field) => `${row.id}:${field}`;

const valueOf = (row, field) => {
  const key = cellKey(row, field);
  const value = key in drafts ? drafts[key] : row[FIELDS[field]];

  // Option values are strings; an owner id arrives as a number and would never match one.
  return value === null || value === undefined ? '' : String(value);
};

const border = (row, field) =>
  ({
    saving: 'border-state-warn/60',
    saved: 'border-state-ok/70',
    error: 'border-state-danger/70',
  })[states[cellKey(row, field)]] ?? 'border-transparent hover:border-hairline';

const save = (row, field, value) => {
  const key = cellKey(row, field);

  if (String(value ?? '') === valueOf(row, field) && !(key in drafts)) {
    return;
  }

  drafts[key] = value;
  states[key] = 'saving';

  // No `only`: a flashed toast rides on the shared props, and a partial reload would
  // drop it. '' is how the client says "nobody" and "not watched yet"; the server takes
  // null for both. A note is genuinely blankable, so it is sent as it stands.
  router.patch(
    row.update_url,
    { [field]: value === '' && field !== 'recording_note' ? null : value },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        delete drafts[key];
        states[key] = 'saved';
        window.setTimeout(() => {
          if (states[key] === 'saved') delete states[key];
        }, 1200);
      },
      onError: () => (states[key] = 'error'),
    },
  );
};

/* --------------------------------------------------------------- keyboard */

/**
 * Up and down move between the same cell of neighbouring rows, which is how a column
 * gets filled in. Enter does the same from a note, so a whole stage can be worked
 * through without the hand leaving the keyboard.
 */
const focusCell = (rowIndex, field) => {
  const row = props.rows[rowIndex];

  if (!row) {
    return;
  }

  nextTick(() => document.querySelector(`[data-cell="${row.id}:${field}"]`)?.focus());
};

const onCellKey = (event, index, field) => {
  if (event.key === 'ArrowDown' || (event.key === 'Enter' && field === 'recording_note')) {
    event.preventDefault();
    focusCell(index + 1, field);
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault();
    focusCell(index - 1, field);
  }

  // Escape drops an uncommitted note rather than saving half a sentence.
  if (event.key === 'Escape' && field === 'recording_note') {
    delete drafts[cellKey(props.rows[index], field)];
    event.target.blur();
  }
};

/* --------------------------------------------------------------- selection */

const selected = ref([]);
const bulk = reactive({
  publish_plan: '',
  recording_owner_id: '',
  stream_condition: '',
  onsite_status: '',
});

// A reload can drop rows out from under the selection; keep only what is still on screen.
watch(
  () => props.rows,
  (rows) => {
    const ids = rows.map((row) => row.id);
    selected.value = selected.value.filter((id) => ids.includes(id));
  },
);

const allSelected = computed(
  () => props.rows.length > 0 && selected.value.length === props.rows.length,
);

const toggleAll = () => {
  selected.value = allSelected.value ? [] : props.rows.map((row) => row.id);
};

const toggleRow = (id) => {
  selected.value = selected.value.includes(id)
    ? selected.value.filter((item) => item !== id)
    : [...selected.value, id];
};

const bulkReady = computed(() => Object.values(bulk).some(Boolean));

const applyBulk = () => {
  const payload = { ids: selected.value };

  if (bulk.publish_plan) {
    payload.publish_plan = bulk.publish_plan;
  }

  if (bulk.recording_owner_id) {
    payload.recording_owner_id = bulk.recording_owner_id === 'none' ? null : bulk.recording_owner_id;
  }

  // 'clear' is the client's way of saying "back to nothing"; the server takes null.
  if (bulk.stream_condition) {
    payload.stream_condition = bulk.stream_condition === 'clear' ? null : bulk.stream_condition;
  }

  if (bulk.onsite_status) {
    payload.onsite_status = bulk.onsite_status === 'clear' ? null : bulk.onsite_status;
  }

  router.post(props.urls.bulk, payload, {
    preserveScroll: true,
    onSuccess: () => {
      selected.value = [];
      Object.keys(bulk).forEach((key) => (bulk[key] = ''));
    },
  });
};

/* ------------------------------------------------------------------- rows */

/**
 * Each group gets a band of its own above its first row, as a spreadsheet would. The rows
 * arrive already in the order the grouping needs, so this only has to notice where one
 * band ends and the next begins.
 */
const groupKey = (row) =>
  ({
    day: row.day,
    owner: row.owner_id ?? 'none',
    source: row.source_id ?? 'none',
  })[props.filters.group] ?? null;

const groupLabel = (row) =>
  ({
    day: row.day_label ?? 'Unscheduled',
    owner: row.owner ?? 'Nobody yet',
    source: row.source ?? 'No source',
  })[props.filters.group] ?? '';

const withGroupBreaks = computed(() =>
  props.rows.map((row, index) => ({
    ...row,
    firstOfGroup:
      props.filters.group !== 'none' &&
      (index === 0 || groupKey(props.rows[index - 1]) !== groupKey(row)),
    index,
  })),
);

/** Rows in the same band, for the band's own count. */
const groupSize = (row) =>
  props.rows.filter((item) => groupKey(item) === groupKey(row)).length;

const planLabel = (row) => props.options.plans.find((plan) => plan.value === row.publish_plan);

/**
 * The archive chips are booleans on the wire and timestamps in the database, so they do
 * not go through `save()`: there is no draft to hold and nothing to compare against.
 */
const toggleArchive = (row, which) => {
  const key = cellKey(row, `archive_${which}`);

  states[key] = 'saving';

  router.patch(
    row.update_url,
    { [`archive_${which}`]: !row[`archive_${which}`] },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        states[key] = 'saved';
        window.setTimeout(() => {
          if (states[key] === 'saved') delete states[key];
        }, 1200);
      },
      onError: () => (states[key] = 'error'),
    },
  );
};

/** Put your own name on a row. The single most common edit on this page by a distance. */
const claim = (row) => save(row, 'recording_owner_id', String(props.me.id));

const claimAll = () => {
  bulk.recording_owner_id = String(props.me.id);
  applyBulk();
};

const cell = 'border-b border-hairline px-2 py-1 align-middle';
const control =
  'h-7 w-full rounded border bg-surface-2 px-1.5 text-[12px] text-fg-1 outline-none transition-colors focus:border-state-live/60';
const filterControl =
  'h-7 rounded border border-hairline bg-surface-2 px-1.5 text-[12px] text-fg-1 outline-none';
</script>

<template>
  <ManageLayout>
    <Head title="Recording Plan" />

    <PageHeader
      title="Recording Plan"
      subtitle="What is being published, who is on it, what still needs the room's copy, and what has reached the archive"
    >
      <template #actions>
        <Link
          :href="urls.recordings"
          class="inline-flex h-7 items-center gap-1 rounded border border-hairline px-2 text-[12px] text-fg-2 hover:bg-surface-3"
        >
          <ManageIcon name="film" :size="13" />
          Recordings
        </Link>
      </template>
    </PageHeader>

    <!-- The counts describe the rows on screen, so a filtered view is also a tally. -->
    <div class="flex flex-wrap gap-2 border-b border-hairline px-3 py-2 md:px-4">
      <button
        v-for="tile in summary"
        :key="tile.key"
        type="button"
        class="flex min-w-[88px] flex-col rounded border bg-surface-1 px-2.5 py-1.5 text-left transition-colors hover:bg-surface-2"
        :class="
          tile.filter && filters[tile.filter[0]] === tile.filter[1]
            ? 'border-state-live/50'
            : 'border-hairline'
        "
        @click="applyTile(tile)"
      >
        <span class="text-[16px] font-semibold tabular-nums" :class="resolve(toneText, tile.tone)">
          {{ tile.value }}
        </span>
        <span class="text-[11px] uppercase tracking-wide text-fg-3">{{ tile.label }}</span>
      </button>
    </div>

    <!-- Filters. All of them live in the query string, so a filtered view is a link. -->
    <div class="flex flex-wrap items-center gap-1.5 border-b border-hairline px-3 py-2 md:px-4">
      <div class="relative">
        <ManageIcon
          name="search"
          :size="13"
          class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-fg-3"
        />
        <input
          v-model="search"
          type="search"
          placeholder="Search shows"
          class="h-7 w-44 rounded border border-hairline bg-surface-2 pl-7 pr-2 text-[12px] text-fg-1 outline-none focus:border-state-live/50"
        />
      </div>

      <!--
        Defaults to the current year rather than to everything: an installation
        accumulates a run of shows per event, and this year's is the one being worked.
      -->
      <select
        :value="filters.year"
        :class="filterControl"
        aria-label="Year"
        @change="setFilter('year', $event.target.value)"
      >
        <option v-for="year in options.years" :key="year.value" :value="year.value">
          {{ year.label }}
        </option>
      </select>

      <select
        :value="filters.day ?? ''"
        :class="filterControl"
        aria-label="Day"
        @change="setFilter('day', $event.target.value)"
      >
        <option value="">Any day</option>
        <option v-for="day in options.days" :key="day.value" :value="day.value">{{ day.label }}</option>
      </select>

      <select
        :value="filters.source ?? ''"
        :class="filterControl"
        aria-label="Source"
        @change="setFilter('source', $event.target.value)"
      >
        <option value="">Any source</option>
        <option v-for="source in options.sources" :key="source.value" :value="source.value">
          {{ source.label }}
        </option>
      </select>

      <select
        :value="filters.plan ?? ''"
        :class="filterControl"
        aria-label="Publish plan"
        @change="setFilter('plan', $event.target.value)"
      >
        <option value="">Publish: any</option>
        <option v-for="plan in options.plans" :key="plan.value" :value="plan.value">
          Publish: {{ plan.label }}
        </option>
      </select>

      <select
        :value="filters.owner ?? ''"
        :class="filterControl"
        aria-label="Owner"
        @change="setFilter('owner', $event.target.value)"
      >
        <option value="">Any owner</option>
        <option value="none">Nobody</option>
        <option v-for="owner in options.owners" :key="owner.value" :value="owner.value">
          {{ owner.label }}
        </option>
      </select>

      <select
        :value="filters.state ?? ''"
        :class="filterControl"
        aria-label="Recording status"
        @change="setFilter('state', $event.target.value)"
      >
        <option value="">Any status</option>
        <option v-for="state in options.states" :key="state.value" :value="state.value">
          {{ state.label }}
        </option>
      </select>

      <select
        :value="filters.group"
        :class="filterControl"
        aria-label="Grouping"
        @change="setFilter('group', $event.target.value)"
      >
        <option v-for="group in options.groups" :key="group.value" :value="group.value">
          {{ group.label }}
        </option>
      </select>

      <!--
        Not the same as picking yourself in the owner list: this one survives being sent
        to somebody else, so "here is what is left on your plate" is a link.
      -->
      <button
        type="button"
        class="inline-flex h-7 items-center gap-1 rounded border px-2 text-[12px] transition-colors"
        :class="
          filters.mine
            ? 'border-state-live/50 bg-state-live/12 text-state-live'
            : 'border-hairline text-fg-2 hover:bg-surface-3'
        "
        @click="setFilter('mine', filters.mine ? null : 1)"
      >
        <ManageIcon name="hand" :size="13" />
        Mine
      </button>

      <label class="flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2">
        <input
          type="checkbox"
          class="accent-state-live"
          :checked="filters.show_archived"
          @change="setFilter('show_archived', $event.target.checked ? 1 : null)"
        />
        Archived
      </label>

      <button
        v-if="activeFilters > 0"
        type="button"
        class="inline-flex h-7 items-center gap-1 rounded border border-hairline px-2 text-[12px] text-fg-2 hover:bg-surface-3"
        @click="clearFilters"
      >
        <ManageIcon name="x" :size="13" />
        Clear
      </button>

      <span class="ml-auto text-[12px] tabular-nums text-fg-3">{{ rows.length }} rows</span>
    </div>

    <!-- Bulk bar: the whole reason the day the programme lands is survivable. -->
    <div
      v-if="can_edit && selected.length > 0"
      class="flex flex-wrap items-center gap-1.5 border-b border-hairline bg-surface-1 px-3 py-2 md:px-4"
    >
      <span class="text-[12px] font-medium text-fg-1">{{ selected.length }} selected</span>

      <select v-model="bulk.publish_plan" :class="filterControl" aria-label="Set publish plan for selection">
        <option value="">Publish…</option>
        <option v-for="plan in options.plans" :key="plan.value" :value="plan.value">{{ plan.label }}</option>
      </select>

      <select v-model="bulk.recording_owner_id" :class="filterControl" aria-label="Set owner for selection">
        <option value="">Responsible…</option>
        <option value="none">Nobody</option>
        <option v-for="owner in options.owners" :key="owner.value" :value="owner.value">
          {{ owner.label }}
        </option>
      </select>

      <select v-model="bulk.stream_condition" :class="filterControl" aria-label="Set stream condition for selection">
        <option value="">Stream…</option>
        <option value="clear">Unchecked</option>
        <option
          v-for="condition in options.streams.filter((item) => item.value)"
          :key="condition.value"
          :value="condition.value"
        >
          {{ condition.label }}
        </option>
      </select>

      <select v-model="bulk.onsite_status" :class="filterControl" aria-label="Set onsite status for selection">
        <option value="">Onsite…</option>
        <option value="clear">Not checked</option>
        <option
          v-for="status in options.onsites.filter((item) => item.value)"
          :key="status.value"
          :value="status.value"
        >
          {{ status.label }}
        </option>
      </select>

      <button
        type="button"
        class="inline-flex h-7 items-center gap-1 rounded border border-state-ok/40 px-2 text-[12px] text-state-ok hover:bg-state-ok/12 disabled:opacity-40"
        :disabled="!bulkReady"
        @click="applyBulk"
      >
        <ManageIcon name="check" :size="13" />
        Apply
      </button>

      <button
        type="button"
        class="inline-flex h-7 items-center gap-1 rounded border border-hairline px-2 text-[12px] text-fg-2 hover:bg-surface-3"
        @click="claimAll"
      >
        <ManageIcon name="hand" :size="13" />
        Take these
      </button>

      <button
        type="button"
        class="inline-flex h-7 items-center gap-1 rounded border border-hairline px-2 text-[12px] text-fg-2 hover:bg-surface-3"
        @click="selected = []"
      >
        Clear selection
      </button>
    </div>

    <div
      v-if="truncated"
      class="flex items-center gap-2 border-b border-hairline bg-state-warn/8 px-3 py-2 text-[12px] text-state-warn md:px-4"
    >
      <ManageIcon name="triangle-alert" :size="14" />
      Showing the first {{ limit }} shows. Narrow the filters to see the rest.
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
      <table class="w-full min-w-[1354px] table-fixed border-separate border-spacing-0 text-[12px]">
        <!--
          Explicit widths because the table is `table-fixed`: the first two columns are
          pinned, and a pinned column whose width the browser may reconsider on the next
          reload lands its neighbour underneath itself.
        -->
        <colgroup>
          <col style="width: 36px" />
          <col style="width: 200px" />
          <col style="width: 96px" />
          <col style="width: 104px" />
          <col style="width: 96px" />
          <col style="width: 112px" />
          <col style="width: 172px" />
          <col style="width: 120px" />
          <col style="width: 128px" />
          <col style="width: 160px" />
          <col style="width: 130px" />
        </colgroup>

        <thead class="sticky top-0 z-30">
          <tr class="bg-surface-1 text-left text-[11px] uppercase tracking-wide text-fg-3">
            <th class="sticky left-0 z-10 border-b border-hairline bg-surface-1 px-2 py-1.5">
              <input
                v-if="can_edit"
                type="checkbox"
                class="accent-state-live"
                :checked="allSelected"
                aria-label="Select all rows"
                @change="toggleAll"
              />
            </th>
            <th class="sticky left-9 z-10 border-b border-r border-hairline bg-surface-1 px-2 py-1.5 font-medium">
              Show
            </th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Time</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Source</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Archive</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Publish?</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Responsible</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Stream</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Onsite</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Note</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Recording</th>
          </tr>
        </thead>

        <tbody>
          <template v-for="row in withGroupBreaks" :key="row.id">
            <tr v-if="row.firstOfGroup" class="bg-surface-1">
              <td colspan="11" class="border-y border-hairline px-3 py-1">
                <!--
                  The label is pinned, not the cell: a cell spanning every column is
                  already at the table's left edge, so `sticky` on it does nothing and the
                  heading scrolls away under the pinned title column.
                -->
                <span
                  class="sticky left-3 inline-flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-fg-2"
                >
                  {{ groupLabel(row) }}
                  <span class="text-fg-3 normal-case tabular-nums">{{ groupSize(row) }}</span>
                </span>
              </td>
            </tr>

            <tr :class="row.gap ? 'bg-state-danger/8' : 'hover:bg-surface-1/50'">
              <td class="sticky left-0 z-10 bg-surface-0 px-2 py-1 align-middle" :class="cell">
                <input
                  v-if="can_edit"
                  type="checkbox"
                  class="accent-state-live"
                  :checked="selected.includes(row.id)"
                  :aria-label="`Select ${row.title}`"
                  @change="toggleRow(row.id)"
                />
              </td>

              <td class="sticky left-9 z-10 border-r bg-surface-0" :class="cell">
                <Link :href="row.url" class="block truncate text-fg-1 hover:text-state-live" :title="row.title">
                  {{ row.title }}
                </Link>
              </td>

              <td class="whitespace-nowrap text-fg-2 tabular-nums" :class="cell">
                {{ row.start }}–{{ row.end }}
              </td>

              <td class="text-fg-2" :class="cell">
                <span class="block truncate">{{ row.source ?? '—' }}</span>
              </td>

              <!--
                The deposit onto the archive FTP, which is a different question from
                whether anyone is publishing the show: both files go up either way. The
                programme mix is what "archived" turns on; the isolated feeds are extra
                and not every show has them.
              -->
              <td class="whitespace-nowrap px-1" :class="cell">
                <div class="flex items-center gap-1">
                  <button
                    v-for="which in ['pgm', 'iso']"
                    :key="which"
                    type="button"
                    :disabled="!can_edit"
                    class="rounded border px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide transition-colors disabled:cursor-default"
                    :class="[
                      row[`archive_${which}`]
                        ? 'border-state-ok/40 bg-state-ok/12 text-state-ok'
                        : which === 'pgm' && row.needs_archive
                          ? 'border-state-warn/40 text-state-warn hover:bg-state-warn/12'
                          : 'border-hairline text-fg-3 hover:bg-surface-3',
                      states[`${row.id}:archive_${which}`] === 'error' ? 'border-state-danger/70' : '',
                    ]"
                    :title="
                      row[`archive_${which}_at`]
                        ? `Uploaded ${row[`archive_${which}_at`]}`
                        : `Not on the archive FTP yet`
                    "
                    :aria-pressed="row[`archive_${which}`]"
                    :aria-label="`${which.toUpperCase()} of ${row.title} on the archive FTP`"
                    @click="can_edit && toggleArchive(row, which)"
                  >
                    {{ which }}
                  </button>
                </div>
              </td>

              <td class="px-1" :class="cell">
                <select
                  v-if="can_edit"
                  :data-cell="`${row.id}:publish_plan`"
                  :class="[control, border(row, 'publish_plan')]"
                  :value="valueOf(row, 'publish_plan')"
                  :aria-label="`Publish ${row.title}?`"
                  @change="save(row, 'publish_plan', $event.target.value)"
                  @keydown="onCellKey($event, row.index, 'publish_plan')"
                >
                  <option v-for="plan in options.plans" :key="plan.value" :value="plan.value">
                    {{ plan.label }}
                  </option>
                </select>
                <span
                  v-else
                  class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] ring-1 ring-inset"
                  :class="resolve(toneBadge, planLabel(row)?.tone)"
                >
                  {{ planLabel(row)?.label }}
                </span>
              </td>

              <td class="px-1" :class="cell">
                <div v-if="can_edit" class="flex items-center gap-1">
                  <select
                    :data-cell="`${row.id}:recording_owner_id`"
                    :class="[control, border(row, 'recording_owner_id')]"
                    :value="valueOf(row, 'recording_owner_id')"
                    :aria-label="`Responsible for ${row.title}`"
                    @change="save(row, 'recording_owner_id', $event.target.value)"
                    @keydown="onCellKey($event, row.index, 'recording_owner_id')"
                  >
                    <option value="">—</option>
                    <option v-for="owner in options.owners" :key="owner.value" :value="owner.value">
                      {{ owner.label }}
                    </option>
                  </select>

                  <!--
                    Taking a row is the most common edit on this page by a distance, and
                    hunting for your own name in a list of thirty is a poor way to do it.
                    Hidden once the row is already yours, so it never reads as a no-op.
                  -->
                  <button
                    v-if="row.owner_id !== me.id"
                    type="button"
                    class="shrink-0 rounded border border-hairline px-1 text-[11px] text-fg-3 transition-colors hover:border-state-live/50 hover:text-state-live"
                    :title="`Take ${row.title}`"
                    :aria-label="`Take ${row.title}`"
                    @click="claim(row)"
                  >
                    Me
                  </button>
                </div>
                <span v-else class="text-fg-2">{{ row.owner ?? '—' }}</span>
              </td>

              <td class="px-1" :class="cell">
                <select
                  v-if="can_edit"
                  :data-cell="`${row.id}:stream_condition`"
                  :class="[
                    control,
                    border(row, 'stream_condition'),
                    row.stream_condition === 'lost' ? 'text-state-danger' : '',
                    row.stream_condition && row.stream_condition !== 'lost' && row.stream_condition !== 'ok'
                      ? 'text-state-warn'
                      : '',
                  ]"
                  :value="valueOf(row, 'stream_condition')"
                  :aria-label="`Stream capture of ${row.title}`"
                  @change="save(row, 'stream_condition', $event.target.value)"
                  @keydown="onCellKey($event, row.index, 'stream_condition')"
                >
                  <option v-for="option in options.streams" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <span v-else class="text-fg-2">
                  {{ options.streams.find((item) => item.value === (row.stream_condition ?? ''))?.label }}
                </span>
              </td>

              <!--
                The fallback column. It is dimmed for every show whose stream capture came
                back clean, because there is nothing to chase there, and lit amber for the
                ones where somebody has to go and find the card. That contrast is the
                whole reason the two captures are separate columns rather than one.
              -->
              <td class="px-1" :class="cell">
                <select
                  v-if="can_edit"
                  :data-cell="`${row.id}:onsite_status`"
                  :class="[
                    control,
                    row.needs_onsite ? 'border-state-warn/60 text-state-warn' : border(row, 'onsite_status'),
                    !row.needs_onsite && !row.onsite_status ? 'opacity-45' : '',
                  ]"
                  :value="valueOf(row, 'onsite_status')"
                  :title="row.needs_onsite ? 'The stream capture failed; find the onsite copy.' : 'Only needed if the stream capture fails.'"
                  :aria-label="`Onsite copy of ${row.title}`"
                  @change="save(row, 'onsite_status', $event.target.value)"
                  @keydown="onCellKey($event, row.index, 'onsite_status')"
                >
                  <option v-for="option in options.onsites" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <span v-else class="text-fg-2" :class="row.needs_onsite ? 'text-state-warn' : ''">
                  {{ options.onsites.find((item) => item.value === (row.onsite_status ?? ''))?.label }}
                </span>
              </td>

              <td class="px-1" :class="cell">
                <input
                  v-if="can_edit"
                  type="text"
                  :data-cell="`${row.id}:recording_note`"
                  :class="[control, border(row, 'recording_note')]"
                  :value="valueOf(row, 'recording_note')"
                  :aria-label="`Note for ${row.title}`"
                  @change="save(row, 'recording_note', $event.target.value)"
                  @keydown="onCellKey($event, row.index, 'recording_note')"
                />
                <span v-else class="block truncate text-fg-2">{{ row.note ?? '—' }}</span>
              </td>

              <td class="whitespace-nowrap" :class="cell">
                <Link
                  v-if="row.recording_url"
                  :href="row.recording_url"
                  class="inline-flex items-center gap-1"
                >
                  <StatusBadge :status="row.state_status" />
                  <span v-if="row.recording_count > 1" class="text-[11px] text-fg-3">
                    ×{{ row.recording_count }}
                  </span>
                </Link>
                <StatusBadge v-else :status="row.state_status" />
              </td>
            </tr>
          </template>

          <tr v-if="rows.length === 0">
            <td colspan="11" class="px-3 py-10 text-center text-fg-3">
              No shows match these filters.
              <!--
                Worth saying: a past year is usually filed away in its entirety, so
                picking one without turning the archive on comes back empty and looks
                like the year has no shows in it.
              -->
              <span v-if="!filters.show_archived && filters.year !== defaults.year" class="block text-[12px]">
                {{ filters.year === 'all' ? 'Earlier years are' : filters.year + ' is' }}
                probably archived. Turn on Archived to see it.
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </ManageLayout>
</template>
