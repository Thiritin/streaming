<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import PlannerTrack from '@/Components/Manage/PlannerTrack.vue';
import { ticksFor, xOf, ZOOM, ZOOM_ORDER } from '@/Components/Manage/plannerTime.js';

const props = defineProps({
  range: { type: Object, required: true },
  lanes: { type: Array, default: () => [] },
  now: { type: String, required: true },
  can: { type: Object, default: () => ({ edit: false }) },
});

// Zoom is a client concern: it changes nothing about which shows are loaded, only how wide
// the same window is drawn, so it never costs a request. Hours by default: that is the zoom
// you can actually place a show at.
const zoomKey = ref('hours');
const zoom = computed(() => ZOOM[zoomKey.value]);

const ticks = computed(() => ticksFor(props.range.from, props.range.days, zoom.value));
const width = computed(() => props.range.days * 24 * zoom.value.pxPerHour);

const nowX = computed(() => xOf(props.now, props.range.from, zoom.value.pxPerHour));
const nowVisible = computed(() => nowX.value >= 0 && nowX.value <= width.value);

// Only the marker and the blocks move on their own; the window stays where it was put.
usePoll(30000, { only: ['lanes', 'now'] });

const shift = (days) => {
  const from = new Date(props.range.from);
  from.setDate(from.getDate() + days);

  router.get(
    route('manage.shows.planner'),
    { from: from.toISOString().slice(0, 10), days: props.range.days },
    { preserveState: true, preserveScroll: true, replace: true },
  );
};

const setDays = (days) => {
  router.get(
    route('manage.shows.planner'),
    { from: new Date(props.range.from).toISOString().slice(0, 10), days },
    { preserveState: true, preserveScroll: true, replace: true },
  );
};

const today = () =>
  router.get(
    route('manage.shows.planner'),
    { days: props.range.days },
    { preserveState: true, preserveScroll: true, replace: true },
  );

const scroller = ref(null);
const panning = ref(false);

/**
 * Middle-button drag pans the timeline, the way it does in an editor or a map. Left-drag is
 * taken by moving blocks, so it cannot double as pan.
 */
const beginPan = (event) => {
  if (event.button !== 1) {
    return;
  }

  // Suppress the browser's own middle-click autoscroll, which would fight this.
  event.preventDefault();

  const origin = { x: event.clientX, y: event.clientY };
  const start = { left: scroller.value.scrollLeft, top: scroller.value.scrollTop };

  panning.value = true;

  const onMove = (moveEvent) => {
    scroller.value.scrollLeft = start.left - (moveEvent.clientX - origin.x);
    scroller.value.scrollTop = start.top - (moveEvent.clientY - origin.y);
  };

  const onUp = () => {
    panning.value = false;
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
  };

  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
};

const chip = (active) =>
  active
    ? 'border-state-live/40 bg-state-live/10 text-state-live'
    : 'border-hairline text-fg-2 hover:bg-surface-3';

const button =
  'inline-flex h-7 items-center gap-1 rounded border px-2 text-[12px] transition-colors';
</script>

<template>
  <ManageLayout>
    <Head title="Planner" />

    <PageHeader
      title="Planner"
      :subtitle="can.edit
        ? 'Drag to move · drag the right edge to resize · double-click empty track to add · middle-drag to pan'
        : 'Read-only: you do not have stream.manage'"
    />

    <!-- Controls: which window, and how wide to draw it. -->
    <div class="flex h-11 flex-wrap items-center gap-2 border-b border-hairline bg-surface-1 px-3">
      <button type="button" :class="[button, chip(false)]" @click="shift(-range.days)">
        <ManageIcon name="chevron-left" :size="13" />
        Back
      </button>
      <button type="button" :class="[button, chip(false)]" @click="today">Today</button>
      <button type="button" :class="[button, chip(false)]" @click="shift(range.days)">
        Forward
        <ManageIcon name="chevron-right" :size="13" />
      </button>

      <span class="mx-1 text-[11px] text-fg-3">
        {{ range.dayLabels[0]?.label }} – {{ range.dayLabels[range.dayLabels.length - 1]?.label }}
      </span>

      <label class="ml-auto flex items-center gap-1.5">
        <span class="text-[11px] uppercase tracking-wide text-fg-3">Days</span>
        <select
          :value="range.days"
          class="h-7 rounded border border-hairline bg-surface-2 px-1.5 text-[12px] text-fg-1"
          @change="setDays(Number($event.target.value))"
        >
          <option v-for="option in [1, 2, 4, 7, 14]" :key="option" :value="option">{{ option }}</option>
        </select>
      </label>

      <div class="flex items-center gap-1">
        <span class="text-[11px] uppercase tracking-wide text-fg-3">Zoom</span>
        <button
          v-for="key in ZOOM_ORDER"
          :key="key"
          type="button"
          :class="[button, chip(key === zoomKey)]"
          @click="zoomKey = key"
        >
          {{ ZOOM[key].label }}
        </button>
      </div>
    </div>

    <!-- One scroller for ruler and lanes together, so they cannot drift apart. -->
    <div
      ref="scroller"
      class="min-h-0 flex-1 overflow-auto"
      :class="panning ? 'cursor-grabbing select-none' : ''"
      @pointerdown="beginPan"
      @auxclick.prevent
    >
      <div class="inline-block min-w-full">
        <div class="sticky top-0 z-20 flex border-b border-hairline bg-surface-2">
          <div class="sticky left-0 z-10 w-40 shrink-0 border-r border-hairline bg-surface-2 px-3 py-1.5">
            <span class="text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3">Source</span>
          </div>

          <div class="relative h-8" :style="{ width: `${width}px` }">
            <span
              v-for="tick in ticks"
              :key="tick.hour"
              class="absolute top-0 bottom-0 flex items-center border-l pl-1 text-[10px] tabular-nums"
              :class="tick.isMidnight ? 'border-hairline font-medium text-fg-2' : 'border-hairline/50 text-fg-3'"
              :style="{ left: `${tick.x}px` }"
            >
              {{ tick.label }}
            </span>
          </div>
        </div>

        <div class="relative">
          <!-- Now line, drawn over the lanes so it is readable at any zoom. -->
          <div
            v-if="nowVisible"
            class="pointer-events-none absolute top-0 bottom-0 z-20 w-px bg-state-live"
            :style="{ left: `${160 + nowX}px` }"
            aria-hidden="true"
          >
            <span class="absolute -top-0.5 -left-1 size-2 rounded-full bg-state-live" />
          </div>

          <PlannerTrack
            v-for="lane in lanes"
            :key="lane.id"
            :lane="lane"
            :from="range.from"
            :days="range.days"
            :zoom="zoom"
            :editable="can.edit"
          />

          <p v-if="!lanes.length" class="px-4 py-10 text-center text-[13px] text-fg-3">
            No sources yet. A planner needs at least one channel to lay shows out on.
          </p>
        </div>
      </div>
    </div>
  </ManageLayout>
</template>
