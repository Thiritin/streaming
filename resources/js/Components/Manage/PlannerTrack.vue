<script setup>
/**
 * One source's track: its shows as blocks, dragged to move and resized from the right edge.
 *
 * Drag state is local and optimistic - the block follows the pointer, and only on release
 * does it PATCH. If the server refuses, the reload puts the block back where it was, so
 * there is no separate rollback to get wrong.
 *
 * A live show is never draggable: its viewers are watching it right now.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { cellsFor, clockOf, MS_PER_MINUTE, overlaps, shift, timeAt, toDate, toLocalIso, widthOf, xOf } from './plannerTime.js';

const props = defineProps({
  lane: { type: Object, required: true },
  from: { type: String, required: true },
  days: { type: Number, required: true },
  zoom: { type: Object, required: true },
  editable: { type: Boolean, default: false },
});

const track = ref(null);

/** { id, mode: 'move' | 'resize', startMinutes, endMinutes } while dragging. */
const dragging = ref(null);

/** The inline quick-create form: { start, end, title } */
const creating = ref(null);

const width = computed(() => props.days * 24 * props.zoom.pxPerHour);

// The grid an operator aims at. Same cells as the ruler above, so a block that looks like it
// lands on 14:00 lands on 14:00.
const cells = computed(() => cellsFor(props.days, props.zoom));

// Alternating day bands, so a week does not read as one undifferentiated strip.
const dayBands = computed(() =>
  Array.from({ length: props.days }, (_, day) => ({
    day,
    x: day * 24 * props.zoom.pxPerHour,
    w: 24 * props.zoom.pxPerHour,
    shaded: day % 2 === 1,
  })),
);

/** While dragging: the block's target time, shown as a guide on the grid. */
const guide = computed(() => {
  const drag = dragging.value;

  if (!drag) {
    return null;
  }

  const block = blocks.value.find((candidate) => candidate.id === drag.id);

  if (!block) {
    return null;
  }

  return {
    x: xOf(block.start, props.from, props.zoom.pxPerHour),
    endX: xOf(block.end, props.from, props.zoom.pxPerHour),
    label: `${clockOf(block.start)}–${clockOf(block.end)}`,
  };
});

/**
 * Blocks with the in-flight drag applied, so the one being moved renders where the pointer
 * is rather than where the server still thinks it is.
 */
const blocks = computed(() =>
  props.lane.shows.map((show) => {
    const drag = dragging.value?.id === show.id ? dragging.value : null;

    const start = drag
      ? shift(show.start, drag.startMinutes, props.zoom.snapMinutes)
      : toDate(show.start);
    const end = drag
      ? shift(show.end, drag.endMinutes, props.zoom.snapMinutes)
      : toDate(show.end);

    return { ...show, start, end, dragging: Boolean(drag) };
  }),
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

const styleOf = (block) => ({
  left: `${xOf(block.start, props.from, props.zoom.pxPerHour)}px`,
  // A very short show still needs to be grabbable.
  width: `${Math.max(widthOf(block.start, block.end, props.zoom.pxPerHour), 18)}px`,
});

const beginDrag = (event, block, mode) => {
  if (!props.editable || block.locked) {
    return;
  }

  event.preventDefault();
  event.stopPropagation();

  const originX = event.clientX;
  const pxPerMinute = props.zoom.pxPerHour / 60;

  dragging.value = { id: block.id, mode, startMinutes: 0, endMinutes: 0 };

  const onMove = (moveEvent) => {
    const minutes = (moveEvent.clientX - originX) / pxPerMinute;

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

    const start = shift(block.start, drag.startMinutes, props.zoom.snapMinutes);
    const end = shift(block.end, drag.endMinutes, props.zoom.snapMinutes);

    // A resize that dragged the end past the start would be rejected by the server; catch
    // it here so the block simply snaps back instead of raising an error toast.
    if (end - start < props.zoom.snapMinutes * MS_PER_MINUTE) {
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
  const start = timeAt(event.clientX - bounds.left, props.from, props.zoom.pxPerHour, props.zoom.snapMinutes);

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
      source_id: props.lane.id,
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
  <div class="flex border-b border-hairline last:border-b-0">
    <!-- Lane label stays put while the track scrolls. -->
    <div class="sticky left-0 z-10 flex w-40 shrink-0 items-center border-r border-hairline bg-surface-1 px-3">
      <span class="truncate text-[12px] font-medium text-fg-1">{{ lane.name }}</span>
    </div>

    <div
      ref="track"
      class="relative h-14"
      :style="{ width: `${width}px` }"
      @dblclick="openCreate"
    >
      <!-- Grid, drawn back to front: day bands, cell lines, then day boundaries. -->
      <div
        v-for="band in dayBands"
        :key="`band-${band.day}`"
        class="absolute top-0 bottom-0"
        :class="band.shaded ? 'bg-surface-2/40' : ''"
        :style="{ left: `${band.x}px`, width: `${band.w}px` }"
        aria-hidden="true"
      />

      <div
        v-for="cell in cells"
        :key="`cell-${cell.x}`"
        class="absolute top-0 bottom-0 border-l"
        :class="cell.isDay
          ? 'border-hairline'
          : cell.isHour
            ? 'border-hairline/60'
            : 'border-hairline/25'"
        :style="{ left: `${cell.x}px` }"
        aria-hidden="true"
      />

      <!-- Drag guide: where this block will actually land. -->
      <div
        v-if="guide"
        class="pointer-events-none absolute top-0 bottom-0 z-10 border-l-2 border-state-live/70 bg-state-live/5"
        :style="{ left: `${guide.x}px`, width: `${Math.max(guide.endX - guide.x, 2)}px` }"
        aria-hidden="true"
      >
        <span class="absolute -top-0.5 left-1 rounded bg-state-live px-1 text-[10px] tabular-nums text-surface-0">
          {{ guide.label }}
        </span>
      </div>

      <button
        v-for="block in blocks"
        :key="block.id"
        type="button"
        class="absolute top-2 bottom-2 overflow-hidden rounded border px-1.5 text-left transition-shadow"
        :class="[
          tone[block.status?.tone] ?? tone.idle,
          clashing.has(block.id) ? 'ring-1 ring-state-danger' : '',
          block.dragging ? 'z-20 shadow-lg' : '',
          editable && !block.locked ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        ]"
        :style="styleOf(block)"
        :title="`${block.title} · ${clockOf(block.start)}–${clockOf(block.end)}${block.locked ? ' · live, locked' : ''}`"
        @pointerdown="beginDrag($event, block, 'move')"
        @dblclick.stop="open(block)"
      >
        <span class="flex items-center gap-1 truncate text-[11px] font-medium leading-4">
          <ManageIcon v-if="block.locked" name="signal" :size="10" class="shrink-0 text-state-live" />
          <ManageIcon v-else-if="block.autoMode" name="cog" :size="10" class="shrink-0 opacity-60" />
          {{ block.title }}
        </span>
        <span class="block truncate text-[10px] tabular-nums opacity-70">
          {{ clockOf(block.start) }}–{{ clockOf(block.end) }}
        </span>

        <!-- Right edge: resize. Absent on a live block, which cannot be moved at all. -->
        <span
          v-if="editable && !block.locked"
          class="absolute top-0 right-0 bottom-0 w-1.5 cursor-ew-resize bg-fg-3/30 opacity-0 hover:opacity-100"
          @pointerdown.stop="beginDrag($event, block, 'resize')"
        />
      </button>

      <!-- Quick create, anchored where the track was double-clicked. -->
      <div
        v-if="creating"
        class="absolute top-1.5 bottom-1.5 z-30 flex items-center gap-1 rounded border border-state-live/50 bg-surface-2 px-1.5 shadow-lg"
        :style="{ left: `${xOf(creating.start, from, zoom.pxPerHour)}px`, minWidth: '220px' }"
      >
        <input
          v-model="creating.title"
          type="text"
          class="h-6 w-40 rounded border border-hairline bg-surface-1 px-1.5 text-[12px] text-fg-1 outline-none"
          :placeholder="`New show at ${clockOf(creating.start)}`"
          autofocus
          @keydown.enter.prevent="submitCreate"
          @keydown.esc="creating = null"
        />
        <button type="button" class="text-[11px] text-state-live" @click="submitCreate">Add</button>
        <button type="button" class="text-[11px] text-fg-3" @click="creating = null">
          <ManageIcon name="x" :size="12" />
        </button>
      </div>
    </div>
  </div>
</template>
