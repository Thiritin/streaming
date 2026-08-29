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
 * Six things per row, and the page is built around one question: what was meant to go out
 * and has not. Publish, who has it, how the two captures came back, whatever else this
 * room tracks as tags, and what the recording actually is.
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
import UserAvatar from '@/Components/Manage/UserAvatar.vue';
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
  onsite_condition: 'onsite_condition',
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

/** The one PATCH every cell goes through, so saving state is drawn the same way. */
const patch = (row, field, payload) => {
  const key = cellKey(row, field);

  states[key] = 'saving';

  // No `only`: a flashed toast rides on the shared props, and a partial reload would
  // drop it.
  router.patch(row.update_url, payload, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      states[key] = 'saved';
      window.setTimeout(() => {
        if (states[key] === 'saved') delete states[key];
      }, 1200);
    },
    onError: () => (states[key] = 'error'),
  });
};

const save = (row, field, value) => {
  const key = cellKey(row, field);

  if (String(value ?? '') === valueOf(row, field) && !(key in drafts)) {
    return;
  }

  drafts[key] = value;

  // '' is how the client says "nobody" and "not checked yet"; the server takes null for
  // both. A note is genuinely blankable, so it is sent as it stands.
  patch(row, field, { [field]: value === '' && field !== 'recording_note' ? null : value });
};

/**
 * A draft is dropped when the server's own rows agree with it, not when its own save
 * comes back.
 *
 * Every cell saves on its own and several can be in flight at once, and each reply
 * carries a whole page of rows - so a reply to an edit made a moment earlier lands after
 * this one and describes the row as it was before this cell was written. Clearing the
 * draft on success handed the screen to whichever reply came last, which is what made a
 * note appear to reset itself for no reason. Holding the draft until the row actually
 * reads back the saved value means a stale reply changes nothing on screen.
 */
watch(
  () => props.rows,
  (rows) => {
    rows.forEach((row) => {
      Object.entries(FIELDS).forEach(([field, prop]) => {
        const key = cellKey(row, field);

        if (key in drafts && String(drafts[key] ?? '') === String(row[prop] ?? '')) {
          delete drafts[key];
        }
      });

      // Tags are a list, so they compare as one rather than as a string.
      const tagKey = cellKey(row, 'recording_tags');

      if (tagKey in drafts && drafts[tagKey].join(' ') === (row.tags ?? []).join(' ')) {
        delete drafts[tagKey];
      }
    });
  },
);

/* ------------------------------------------------------------------- tags */

/**
 * Whatever else this room tracks - "saved to nas", "handed to editor". Free text on
 * purpose: a room's process is its own, and the suggestion list is every tag anybody has
 * already typed, which is what keeps thirty people's typing to one vocabulary.
 */
const tagsOf = (row) => {
  const key = cellKey(row, 'recording_tags');

  return key in drafts ? drafts[key] : (row.tags ?? []);
};

/** Which row's tag box is open. One at a time: a row of open text inputs reads as noise. */
const tagging = ref(null);
const tagDraft = ref('');

const openTags = (row) => {
  tagging.value = row.id;
  tagDraft.value = '';
  nextTick(() => document.querySelector(`[data-tag-input="${row.id}"]`)?.focus());
};

const writeTags = (row, tags) => {
  drafts[cellKey(row, 'recording_tags')] = tags;
  patch(row, 'recording_tags', { recording_tags: tags });
};

const commitTag = (row) => {
  const tag = tagDraft.value.trim().toLowerCase();
  tagDraft.value = '';

  if (tag === '' || tagsOf(row).includes(tag)) {
    return;
  }

  writeTags(row, [...tagsOf(row), tag]);
};

const dropTag = (row, tag) => writeTags(row, tagsOf(row).filter((item) => item !== tag));

const closeTags = (row) => {
  commitTag(row);
  tagging.value = null;
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
  onsite_condition: '',
  add_tag: '',
  remove_tag: '',
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

const bulkReady = computed(() => Object.values(bulk).some((value) => String(value).trim() !== ''));

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

  if (bulk.onsite_condition) {
    payload.onsite_condition = bulk.onsite_condition === 'clear' ? null : bulk.onsite_condition;
  }

  // Added and removed rather than replaced: a selection spans rows carrying different
  // tags, and one Apply must not flatten them all to the same list.
  if (bulk.add_tag.trim()) {
    payload.add_tag = bulk.add_tag.trim();
  }

  if (bulk.remove_tag) {
    payload.remove_tag = bulk.remove_tag;
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

/** Put your own name on a row. The single most common edit on this page by a distance. */
const claim = (row) => save(row, 'recording_owner_id', String(props.me.id));

const claimAll = () => {
  bulk.recording_owner_id = String(props.me.id);
  applyBulk();
};

const onsiteHint = (row) =>
  row.needs_onsite
    ? 'The stream capture is gone. Find the copy from the room.'
    : 'Only needed if the stream capture fails.';

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
      subtitle="What is being published, who is on it, and whether usable material came back"
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

    <!--
      The counts describe the rows on screen, so a filtered view is also a tally. To
      publish leads them because it is the question the page exists to answer.
    -->
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
        Defaults to the run that is on, or the one that just finished, rather than to
        everything: an installation accumulates a run of shows per event, and the one
        being worked is the one this page is opened for.
      -->
      <select
        :value="filters.event"
        :class="filterControl"
        aria-label="Event"
        @change="setFilter('event', $event.target.value)"
      >
        <option v-for="option in options.events" :key="option.value" :value="option.value">
          {{ option.label }}
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

      <!-- Only worth offering once somebody has typed one. -->
      <select
        v-if="options.tags.length > 0"
        :value="filters.tag ?? ''"
        :class="filterControl"
        aria-label="Tag"
        @change="setFilter('tag', $event.target.value)"
      >
        <option value="">Any tag</option>
        <option v-for="tag in options.tags" :key="tag" :value="tag">{{ tag }}</option>
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
        <option value="clear">Not checked</option>
        <option
          v-for="condition in options.streams.filter((item) => item.value)"
          :key="condition.value"
          :value="condition.value"
        >
          {{ condition.label }}
        </option>
      </select>

      <select v-model="bulk.onsite_condition" :class="filterControl" aria-label="Set onsite condition for selection">
        <option value="">Onsite…</option>
        <option value="clear">Not checked</option>
        <option
          v-for="condition in options.onsites.filter((item) => item.value)"
          :key="condition.value"
          :value="condition.value"
        >
          {{ condition.label }}
        </option>
      </select>

      <input
        v-model="bulk.add_tag"
        type="text"
        list="recording-tags"
        placeholder="Add tag…"
        class="h-7 w-32 rounded border border-hairline bg-surface-2 px-1.5 text-[12px] text-fg-1 outline-none"
        aria-label="Add a tag to the selection"
      />

      <select
        v-if="options.tags.length > 0"
        v-model="bulk.remove_tag"
        :class="filterControl"
        aria-label="Remove a tag from the selection"
      >
        <option value="">Remove tag…</option>
        <option v-for="tag in options.tags" :key="tag" :value="tag">{{ tag }}</option>
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

    <!-- Shared by every tag box on the page, so the vocabulary is offered everywhere. -->
    <datalist id="recording-tags">
      <option v-for="tag in options.tags" :key="tag" :value="tag" />
    </datalist>

    <div class="min-h-0 flex-1 overflow-auto">
      <table class="w-full min-w-[1300px] table-fixed border-separate border-spacing-0 text-[12px]">
        <!--
          Explicit widths because the table is `table-fixed`: the first two columns are
          pinned, and a pinned column whose width the browser may reconsider on the next
          reload lands its neighbour underneath itself.
        -->
        <colgroup>
          <col style="width: 36px" />
          <col style="width: 210px" />
          <col style="width: 92px" />
          <col style="width: 104px" />
          <col style="width: 112px" />
          <col style="width: 176px" />
          <col style="width: 108px" />
          <col style="width: 124px" />
          <col style="width: 190px" />
          <col style="width: 160px" />
          <col style="width: 128px" />
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
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Publish</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Responsible</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Stream</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Onsite</th>
            <th class="border-b border-hairline bg-surface-1 px-2 py-1.5 font-medium">Tags</th>
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
                  <UserAvatar v-if="filters.group === 'owner'" :name="row.owner" :size="16" />
                  {{ groupLabel(row) }}
                  <span class="text-fg-3 normal-case tabular-nums">{{ groupSize(row) }}</span>
                </span>
              </td>
            </tr>

            <!--
              A gap is tinted: meant to go out, has aired, nothing cut and no reason
              recorded. A row whose material is gone for good is dimmed instead - nothing
              can be done about it, and it should not read as work.
            -->
            <tr
              :class="[
                row.gap ? 'bg-state-danger/8' : 'hover:bg-surface-1/50',
                row.lost ? 'opacity-55' : '',
              ]"
            >
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

              <!--
                The avatar rather than the name alone: the same person is always the same
                colour, so a column of thirty rows can be scanned for whose is whose.
              -->
              <td class="px-1" :class="cell">
                <div v-if="can_edit" class="flex items-center gap-1">
                  <UserAvatar :name="row.owner" />
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
                <span v-else class="flex items-center gap-1 text-fg-2">
                  <UserAvatar :name="row.owner" />
                  {{ row.owner ?? '—' }}
                </span>
              </td>

              <!--
                Two answers and no more: whatever went wrong with the stream capture, the
                next move is the same - go and get the copy from the room.
              -->
              <td class="px-1" :class="cell">
                <select
                  v-if="can_edit"
                  :data-cell="`${row.id}:stream_condition`"
                  :class="[
                    control,
                    border(row, 'stream_condition'),
                    row.stream_condition === 'lost' ? 'text-state-danger' : '',
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
                The fallback column, and the one that keeps its detail: missing audio can
                be lifted off the desk and a missing part is still worth publishing, so
                only lost is red. Dimmed while the stream capture is fine, because there
                is nothing to go and find.
              -->
              <td class="px-1" :class="cell">
                <select
                  v-if="can_edit"
                  :data-cell="`${row.id}:onsite_condition`"
                  :class="[
                    control,
                    row.needs_onsite && !row.onsite_condition
                      ? 'border-state-warn/60 text-state-warn'
                      : border(row, 'onsite_condition'),
                    row.onsite_condition === 'lost' ? 'text-state-danger' : '',
                    !row.needs_onsite && !row.onsite_condition ? 'opacity-45' : '',
                  ]"
                  :value="valueOf(row, 'onsite_condition')"
                  :title="onsiteHint(row)"
                  :aria-label="`Onsite copy of ${row.title}`"
                  @change="save(row, 'onsite_condition', $event.target.value)"
                  @keydown="onCellKey($event, row.index, 'onsite_condition')"
                >
                  <option v-for="option in options.onsites" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <span v-else class="text-fg-2" :class="row.needs_onsite ? 'text-state-warn' : ''">
                  {{ options.onsites.find((item) => item.value === (row.onsite_condition ?? ''))?.label }}
                </span>
              </td>

              <!--
                Whatever else this room tracks. The box only appears when it is asked for,
                so a row that carries no tags is one chip wide rather than an empty input.
              -->
              <td class="px-1" :class="cell">
                <div class="flex flex-wrap items-center gap-1">
                  <span
                    v-for="tag in tagsOf(row)"
                    :key="tag"
                    class="inline-flex items-center gap-1 rounded bg-surface-3 px-1.5 py-0.5 text-[11px] text-fg-2"
                  >
                    {{ tag }}
                    <button
                      v-if="can_edit"
                      type="button"
                      class="text-fg-3 hover:text-state-danger"
                      :aria-label="`Remove ${tag} from ${row.title}`"
                      @click="dropTag(row, tag)"
                    >
                      <ManageIcon name="x" :size="10" />
                    </button>
                  </span>

                  <input
                    v-if="can_edit && tagging === row.id"
                    v-model="tagDraft"
                    type="text"
                    list="recording-tags"
                    :data-tag-input="row.id"
                    class="h-6 w-24 rounded border border-state-live/50 bg-surface-2 px-1 text-[11px] text-fg-1 outline-none"
                    :aria-label="`Add a tag to ${row.title}`"
                    @keydown.enter.prevent="commitTag(row)"
                    @keydown.escape="tagging = null"
                    @blur="closeTags(row)"
                  />
                  <button
                    v-else-if="can_edit"
                    type="button"
                    class="rounded border border-hairline px-1 text-[11px] text-fg-3 transition-colors hover:border-state-live/50 hover:text-state-live"
                    :aria-label="`Tag ${row.title}`"
                    @click="openTags(row)"
                  >
                    +
                  </button>
                </div>
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
                Worth saying: a past run is usually filed away in its entirety, so
                picking one without turning the archive on comes back empty and looks
                like the run has no shows in it.
              -->
              <span v-if="!filters.show_archived && filters.event !== defaults.event" class="block text-[12px]">
                {{ filters.event === 'all' ? 'Earlier events are' : 'That event is' }}
                probably archived. Turn on Archived to see it.
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </ManageLayout>
</template>
