<script setup>
/**
 * One source's column for a day: its shows as blocks, dragged to move and resized
 * from the bottom edge.
 *
 * Drag state is local and optimistic - the block follows the pointer, and only on
 * release does it PATCH. If the server refuses, the reload puts the block back where
 * it was, so there is no separate rollback to get wrong.
 *
 * A live show is never draggable: its viewers are watching it right now.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import {
  clockOf,
  lengthOf,
  MS_PER_MINUTE,
  offsetOf,
  overlaps,
  packColumns,
  shift,
  SNAP_MINUTES,
  timeAt,
  toDate,
  toLocalIso,
} from './plannerTime.js';

const props = defineProps({
  column: { type: Object, required: true },
  /** Midnight of the day being drawn; every offset is measured from it. */
  dayStart: { type: String, required: true },
  hours: { type: Object, required: true },
  pxPerHour: { type: Number, default: 64 },
  editable: { type: Boolean, default: false },
});

const track = ref(null);

/** { id, mode: 'move' | 'resize', startMinutes, endMinutes } while dragging. */
const dragging = ref(null);

/** The inline quick-create form: { start, end, title } */
const creating = ref(null);

// Offsets are measured from midnight, but the grid starts at the first drawn hour.
const origin = computed(() => -props.hours.from * props.pxPerHour);

const height = computed(() => (props.hours.to - props.hours.from) * props.pxPerHour);

/**
 * Blocks with the in-flight drag applied, so the one being moved renders where the
 * pointer is rather than where the server still thinks it is, and with overlapping
 * ones packed side by side.
 */
const blocks = computed(() =>
  packColumns(
    props.column.shows.map((show) => {
      const drag = dragging.value?.id === show.id ? dragging.value : null;

      return {
        ...show,
        start: drag ? shift(show.start, drag.startMinutes) : toDate(show.start),
        end: drag ? shift(show.end, drag.endMinutes) : toDate(show.end),
        dragging: Boolean(drag),
      };
    }),
  ),
);

// Flagged, not blocked: an overlap is sometimes real and has to be visible to be fixed.
const clashing = computed(() => {
  const ids = new Set();

  blocks.value.forEach((a, index) => {
    blocks.value.slice(index + 1).forEach((b) => {
      if (overlaps(a, b)) {
        ids.add(a.id);
        ids.add(b.id);
      }
    });
  });

  return ids;
});

const styleOf = (block) => {
  const top = origin.value + offsetOf(block.start, props.dayStart, props.pxPerHour);
  const width = 100 / block.lanes;

  return {
    top: `${top}px`,
    // A very short show still needs to be grabbable.
    height: `${Math.max(lengthOf(block.start, block.end, props.pxPerHour), 20)}px`,
    left: `${block.lane * width}%`,
    width: `calc(${width}% - 4px)`,
  };
};

const beginDrag = (event, block, mode) => {
  if (!props.editable || block.locked) {
    return;
  }

  event.preventDefault();
  event.stopPropagation();

  const originY = event.clientY;
  const pxPerMinute = props.pxPerHour / 60;

  dragging.value = { id: block.id, mode, startMinutes: 0, endMinutes: 0 };

  const onMove = (moveEvent) => {
    const minutes = (moveEvent.clientY - originY) / pxPerMinute;

    dragging.value = {
      id: block.id,
      mode,
      // Moving carries both edges; resizing only pushes the end.
      startMinutes: mode === 'move' ? minutes : 0,
      endMinutes: minutes,
    };
  };

  const onUp = () => {
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);

    const drag = dragging.value;
    dragging.value = null;

    if (!drag || (drag.startMinutes === 0 && drag.endMinutes === 0)) {
      return;
    }

    const start = shift(block.start, drag.startMinutes);
    const end = shift(block.end, drag.endMinutes);

    // A resize that dragged the end past the start would be rejected by the server;
    // catch it here so the block simply snaps back instead of raising an error toast.
    if (end - start < SNAP_MINUTES * MS_PER_MINUTE) {
      return;
    }

    router.patch(
      route('manage.shows.reschedule', block.id),
      { scheduled_start: toLocalIso(start), scheduled_end: toLocalIso(end) },
      { preserveScroll: true, preserveState: true },
    );
  };

  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
};

const openCreate = (event) => {
  if (!props.editable || dragging.value) {
    return;
  }

  const bounds = track.value.getBoundingClientRect();
  const offset = event.clientY - bounds.top - origin.value;
  const start = timeAt(offset, props.dayStart, props.pxPerHour);

  creating.value = {
    start,
    end: new Date(start.getTime() + 60 * MS_PER_MINUTE),
    title: '',
  };
};

const submitCreate = () => {
  if (!creating.value?.title.trim()) {
    creating.value = null;

    return;
  }

  router.post(
    route('manage.shows.planner.store'),
    {
      title: creating.value.title.trim(),
      source_id: props.column.id,
      scheduled_start: toLocalIso(creating.value.start),
      scheduled_end: toLocalIso(creating.value.end),
    },
    {
      preserveScroll: true,
      onFinish: () => {
        creating.value = null;
      },
    },
  );
};

const open = (block) => router.visit(block.url);

const tone = {
  live: 'bg-state-live/25 border-state-live/60 text-fg-1',
  ok: 'bg-state-ok/20 border-state-ok/50 text-fg-1',
  warn: 'bg-state-warn/15 border-state-warn/45 text-fg-1',
  idle: 'bg-surface-3 border-hairline text-fg-2',
  danger: 'bg-state-danger/15 border-state-danger/45 text-fg-2',
  info: 'bg-state-info/15 border-state-info/45 text-fg-1',
};
</script>

<template>
  <div
    ref="track"
    class="relative min-w-40 flex-1 border-r border-hairline last:border-r-0"
    :style="{ height: `${height}px` }"
    @dblclick="openCreate"
  >
    <!-- Hour bands. The quarter lines live on the ruler side; in the column they
         would out-shout the blocks. -->
    <div
      v-for="hour in hours.to - hours.from"
      :key="`band-${hour}`"
      class="absolute right-0 left-0 border-t border-hairline/40"
      :style="{ top: `${(hour - 1) * pxPerHour}px`, height: `${pxPerHour}px` }"
      aria-hidden="true"
    />

    <button
      v-for="block in blocks"
      :key="block.id"
      type="button"
      class="absolute flex flex-col items-start justify-start overflow-hidden rounded border px-1.5 py-1 text-left transition-shadow"
      :class="[
        tone[block.status?.tone] ?? tone.idle,
        clashing.has(block.id) ? 'ring-1 ring-state-danger' : '',
        block.dragging ? 'z-20 shadow-lg' : 'z-10',
        editable && !block.locked ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
      ]"
      :style="styleOf(block)"
      :title="`${block.title} · ${clockOf(block.start)}–${clockOf(block.end)}${block.locked ? ' · live, locked' : ''}`"
      @pointerdown="beginDrag($event, block, 'move')"
      @dblclick.stop="open(block)"
    >
      <span class="flex w-full items-center gap-1 truncate text-[11px] font-medium leading-4">
        <ManageIcon v-if="block.locked" name="signal" :size="10" class="shrink-0 text-state-live" />
        <ManageIcon v-else-if="block.autoMode" name="cog" :size="10" class="shrink-0 opacity-60" />
        {{ block.title }}
      </span>
      <span class="block w-full truncate text-[10px] tabular-nums opacity-70">
        {{ clockOf(block.start) }}–{{ clockOf(block.end) }}
      </span>

      <!-- Bottom edge: resize. Absent on a live block, which cannot be moved at all. -->
      <span
        v-if="editable && !block.locked"
        class="absolute right-0 bottom-0 left-0 h-1.5 cursor-ns-resize bg-fg-3/30 opacity-0 hover:opacity-100"
        @pointerdown.stop="beginDrag($event, block, 'resize')"
      />
    </button>

    <!-- Quick create, anchored where the column was double-clicked. -->
    <div
      v-if="creating"
      class="absolute right-1 left-1 z-30 flex items-center gap-1 rounded border border-state-live/50 bg-surface-2 px-1.5 py-1 shadow-lg"
      :style="{ top: `${origin + offsetOf(creating.start, dayStart, pxPerHour)}px` }"
    >
      <input
        v-model="creating.title"
        type="text"
        class="h-6 min-w-0 flex-1 rounded border border-hairline bg-surface-1 px-1.5 text-[12px] text-fg-1 outline-none"
        :placeholder="`New show at ${clockOf(creating.start)}`"
        autofocus
        @keydown.enter.prevent="submitCreate"
        @keydown.esc="creating = null"
        @dblclick.stop
      />
      <button type="button" class="text-[11px] text-state-live" @click="submitCreate">Add</button>
      <button type="button" class="text-[11px] text-fg-3" @click="creating = null">
        <ManageIcon name="x" :size="12" />
      </button>
    </div>
  </div>
</template>
