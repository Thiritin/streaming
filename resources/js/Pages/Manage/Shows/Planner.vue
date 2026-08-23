<script setup>
/**
 * The programme for one day: sources across the top, hours down the side.
 *
 * Rendered without the manage rail. Laying out a day wants the whole window, and the
 * page is reached from Shows and closes back to it, so it reads as a mode rather than
 * a section.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PlannerColumn from '@/Components/Manage/PlannerColumn.vue';
import { hoursBetween, offsetOf } from '@/Components/Manage/plannerTime.js';

const props = defineProps({
  day: { type: Object, required: true },
  columns: { type: Array, default: () => [] },
  hours: { type: Object, required: true },
  now: { type: String, required: true },
  pxPerHour: { type: Number, default: 64 },
  closeUrl: { type: String, required: true },
  can: { type: Object, default: () => ({ edit: false }) },
});

// The server opens on the hours that hold the programme; this is the way back to all
// twenty-four, for a show that has to be placed at four in the morning.
const fullDay = ref(false);

const window_ = computed(() => (fullDay.value ? { from: 0, to: 24 } : props.hours));

const hourRows = computed(() => hoursBetween(window_.value.from, window_.value.to));

const gridHeight = computed(() => (window_.value.to - window_.value.from) * props.pxPerHour);

const nowTop = computed(
  () => offsetOf(props.now, props.day.start, props.pxPerHour) - window_.value.from * props.pxPerHour,
);

const nowVisible = computed(
  () => props.day.isToday && nowTop.value >= 0 && nowTop.value <= gridHeight.value,
);

// Only the blocks and the marker move on their own; the day stays where it was put.
usePoll(30000, { only: ['columns', 'now'] });

const goTo = (date) =>
  router.get(
    route('manage.shows.planner'),
    { date },
    { preserveState: true, preserveScroll: true, replace: true },
  );

const onKey = (event) => {
  if (event.target.tagName === 'INPUT') return;

  if (event.key === 'Escape') router.visit(props.closeUrl);
  if (event.key === 'ArrowLeft') goTo(props.day.previous);
  if (event.key === 'ArrowRight') goTo(props.day.next);
};

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const button =
  'inline-flex h-8 items-center gap-1 rounded border border-hairline px-2.5 text-[12px] text-fg-2 transition-colors hover:bg-surface-3';
</script>

<template>
  <div class="flex h-screen flex-col bg-surface-0 text-fg-1">
    <Head :title="`Planner · ${day.label}`" />

    <header class="flex shrink-0 flex-wrap items-center gap-2 border-b border-hairline bg-surface-1 px-3 py-2">
      <button type="button" :class="button" :aria-label="'Previous day'" @click="goTo(day.previous)">
        <ManageIcon name="chevron-left" :size="14" />
      </button>
      <button type="button" :class="button" @click="goTo(new Date().toISOString().slice(0, 10))">
        Today
      </button>
      <button type="button" :class="button" :aria-label="'Next day'" @click="goTo(day.next)">
        <ManageIcon name="chevron-right" :size="14" />
      </button>

      <h1 class="ml-1 text-[13px] font-semibold">
        {{ day.label }}
        <span v-if="day.isToday" class="ml-1 text-[11px] font-normal text-state-live">today</span>
      </h1>

      <label class="ml-auto flex items-center gap-1.5 text-[12px] text-fg-2">
        <input v-model="fullDay" type="checkbox" class="size-3.5 accent-current" />
        Full 24 hours
      </label>

      <span class="hidden text-[11px] text-fg-3 lg:inline">
        {{ can.edit
          ? 'Drag to move · drag the bottom edge to resize · double-click a column to add'
          : 'Read-only: you do not have stream.manage' }}
      </span>

      <Link :href="closeUrl" :class="button">
        <ManageIcon name="x" :size="14" />
        Close
      </Link>
    </header>

    <div class="min-h-0 flex-1 overflow-auto">
      <div class="flex min-w-max">
        <!-- Hour ruler. Sticky sideways so it stays put when many sources push the
             grid wider than the window. -->
        <div class="sticky left-0 z-30 w-14 shrink-0 border-r border-hairline bg-surface-1">
          <div class="sticky top-0 z-10 h-9 border-b border-hairline bg-surface-2" />

          <div class="relative mt-2" :style="{ height: `${gridHeight}px` }">
            <!-- Labels straddle their hour line. The grid carries a couple of pixels
                 of air above it on both sides, so the first one is not clipped by the
                 header. -->
            <span
              v-for="(row, index) in hourRows"
              :key="row.hour"
              class="absolute right-1 -translate-y-1/2 text-[10px] tabular-nums text-fg-3"
              :style="{ top: `${index * pxPerHour}px` }"
            >
              {{ row.label }}
            </span>
          </div>
        </div>

        <div class="relative min-w-0 flex-1">
          <!-- Source headings, one per column, pinned while the day scrolls. -->
          <div class="sticky top-0 z-20 flex h-9 border-b border-hairline bg-surface-2">
            <div
              v-for="column in columns"
              :key="column.id"
              class="flex min-w-40 flex-1 items-center border-r border-hairline px-2 last:border-r-0"
            >
              <span class="truncate text-[12px] font-medium">{{ column.name }}</span>
              <span class="ml-auto text-[10px] tabular-nums text-fg-3">{{ column.shows.length }}</span>
            </div>
          </div>

          <div class="relative mt-2 flex">
            <PlannerColumn
              v-for="column in columns"
              :key="column.id"
              :column="column"
              :day-start="day.start"
              :hours="window_"
              :px-per-hour="pxPerHour"
              :editable="can.edit"
            />

            <!-- Now line, drawn over the columns so it is readable against a block. -->
            <div
              v-if="nowVisible"
              class="pointer-events-none absolute right-0 left-0 z-30 h-px bg-state-live"
              :style="{ top: `${nowTop}px` }"
              aria-hidden="true"
            >
              <span class="absolute -top-1 -left-1 size-2 rounded-full bg-state-live" />
            </div>
          </div>

          <p v-if="!columns.length" class="px-4 py-10 text-center text-[13px] text-fg-3">
            No sources yet. A planner needs at least one channel to lay shows out on.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
