<script setup>
/**
 * Marks the stretches of a recording a viewer may be offered a way past.
 *
 * One component for both places it is done: the recording form in /manage, and the
 * watch page itself, where an operator who has just sat through an intermission can
 * mark it without leaving the player. The timeline is the primary control - an
 * intermission is a shape in a recording, not two numbers - and the rows underneath
 * are what makes it exact, and reachable without a pointer.
 *
 * Everything is seconds from the start of the recording. Overlaps are left alone
 * while dragging and merged by the server on save, so a drag never rearranges the
 * thing under the cursor.
 */
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  /** Length of the recording. Without one there is no timeline to draw. */
  duration: { type: Number, default: 0 },
  /** Where the player is, if there is one. Null in the form, where nothing plays. */
  currentTime: { type: Number, default: null },
  /** Shorter than this and the button would be gone before it was read. */
  minSeconds: { type: Number, default: 5 },
  max: { type: Number, default: 20 },
});

const emit = defineEmits(['update:modelValue', 'seek']);

const track = ref(null);
const dragging = ref(null);

const segments = computed(() => props.modelValue ?? []);
const usable = computed(() => props.duration > 0);
const full = computed(() => segments.value.length >= props.max);

const pct = (seconds) => Math.min(100, Math.max(0, (seconds / props.duration) * 100));

const playheadPct = computed(() =>
  usable.value && props.currentTime !== null ? pct(props.currentTime) : null
);

const clamp = (seconds) => Math.min(props.duration, Math.max(0, Math.round(seconds)));

const timeAt = (clientX) => {
  const rect = track.value?.getBoundingClientRect();
  if (!rect?.width) return 0;

  return clamp(((clientX - rect.left) / rect.width) * props.duration);
};

// Sorted on every write, so the rows underneath read in the order they play and a
// segment dragged past its neighbour does not stay behind it in the list.
const commit = (next) => emit('update:modelValue', [...next].sort((a, b) => a.start - b.start));

const update = (index, changes) =>
  commit(segments.value.map((segment, at) => (at === index ? { ...segment, ...changes } : segment)));

const remove = (index) => commit(segments.value.filter((_, at) => at !== index));

const addAt = (start) => {
  if (full.value || !usable.value) return;

  const from = clamp(start);
  const to = clamp(Math.max(from + props.minSeconds, from + 60));

  if (to <= from) return;

  commit([...segments.value, { start: from, end: to, label: null }]);
};

/*
 * Drag on the track. Three grips on one handler: the body moves a segment, an edge
 * resizes it, and empty track draws a new one, which is the only way to place one
 * without first knowing the numbers.
 */
const onTrackDown = (event) => {
  if (!usable.value || full.value || event.button !== 0) return;

  const start = timeAt(event.clientX);
  const end = clamp(start + props.minSeconds);

  if (end <= start) return;

  const next = [...segments.value, { start, end, label: null }];
  emit('update:modelValue', next);

  beginDrag(event, next.length - 1, 'end', 0, next);
};

const onSegmentDown = (event, index, mode) => {
  if (event.button !== 0) return;

  event.stopPropagation();

  const segment = segments.value[index];
  const grab = timeAt(event.clientX) - segment.start;

  beginDrag(event, index, mode, grab, segments.value);
};

const beginDrag = (event, index, mode, grab, list) => {
  dragging.value = { index, mode, grab, list: [...list] };

  event.target.setPointerCapture?.(event.pointerId);

  window.addEventListener('pointermove', onDragMove);
  window.addEventListener('pointerup', endDrag);
  window.addEventListener('pointercancel', endDrag);
};

const onDragMove = (event) => {
  const drag = dragging.value;
  if (!drag) return;

  event.preventDefault();

  const at = timeAt(event.clientX);
  const list = [...drag.list];
  const segment = { ...list[drag.index] };
  const length = segment.end - segment.start;

  if (drag.mode === 'move') {
    segment.start = clamp(Math.min(at - drag.grab, props.duration - length));
    segment.end = segment.start + length;
  } else if (drag.mode === 'start') {
    segment.start = Math.min(at, segment.end - props.minSeconds);
    segment.start = Math.max(0, segment.start);
  } else {
    segment.end = Math.max(at, segment.start + props.minSeconds);
    segment.end = Math.min(props.duration, segment.end);
  }

  list[drag.index] = segment;
  drag.list = list;

  // Unsorted while the pointer is down: sorting mid-drag would move the segment out
  // from under the cursor the moment it crossed its neighbour.
  emit('update:modelValue', list);
};

const endDrag = () => {
  const drag = dragging.value;

  window.removeEventListener('pointermove', onDragMove);
  window.removeEventListener('pointerup', endDrag);
  window.removeEventListener('pointercancel', endDrag);

  dragging.value = null;

  if (drag) commit(drag.list);
};

onBeforeUnmount(endDrag);

const formatClock = (seconds) => {
  const value = Math.max(0, Math.floor(seconds ?? 0));
  const hours = Math.floor(value / 3600);
  const minutes = Math.floor((value % 3600) / 60);
  const rest = value % 60;

  return hours > 0
    ? `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
    : `${minutes}:${String(rest).padStart(2, '0')}`;
};

/** Accepts 90, 1:30 and 1:02:03 alike: an operator reading a clock types either. */
const parseClock = (value) => {
  const parts = String(value).trim().split(':').map((part) => Number(part));

  if (parts.some((part) => Number.isNaN(part))) return null;

  return parts.reduce((total, part) => total * 60 + part, 0);
};

const onClockInput = (index, field, event) => {
  const seconds = parseClock(event.target.value);
  const segment = segments.value[index];

  if (seconds === null) {
    event.target.value = formatClock(segment[field]);

    return;
  }

  const next = clamp(seconds);

  const changes =
    field === 'start'
      ? { start: Math.min(next, segment.end - props.minSeconds) }
      : { end: Math.max(next, segment.start + props.minSeconds) };

  update(index, changes);
  event.target.value = formatClock({ ...segment, ...changes }[field]);
};
</script>

<template>
  <div class="skip-editor">
    <template v-if="usable">
      <div
        ref="track"
        class="skip-track"
        :class="{ 'is-full': full }"
        @pointerdown="onTrackDown"
      >
        <div
          v-for="(segment, index) in segments"
          :key="index"
          class="skip-block"
          :style="{ left: `${pct(segment.start)}%`, width: `${Math.max(0.6, pct(segment.end) - pct(segment.start))}%` }"
          @pointerdown="onSegmentDown($event, index, 'move')"
        >
          <span class="skip-grip" @pointerdown="onSegmentDown($event, index, 'start')" />
          <span class="skip-block-label">{{ segment.label || 'Skip' }}</span>
          <span class="skip-grip skip-grip-end" @pointerdown="onSegmentDown($event, index, 'end')" />
        </div>

        <div v-if="playheadPct !== null" class="skip-playhead" :style="{ left: `${playheadPct}%` }" />
      </div>

      <div class="skip-actions">
        <button type="button" class="skip-btn" :disabled="full" @click="addAt(currentTime ?? 0)">
          {{ currentTime !== null ? `Add at ${formatClock(currentTime)}` : 'Add skip point' }}
        </button>
        <p class="skip-hint">
          Drag on the bar to draw one, drag its edges to trim. {{ segments.length }}/{{ max }}.
        </p>
      </div>

      <ul v-if="segments.length" class="skip-rows">
        <li v-for="(segment, index) in segments" :key="index" class="skip-row">
          <input
            class="skip-time"
            :value="formatClock(segment.start)"
            aria-label="Skip starts at"
            @change="onClockInput(index, 'start', $event)"
          />
          <span class="skip-dash" aria-hidden="true">to</span>
          <input
            class="skip-time"
            :value="formatClock(segment.end)"
            aria-label="Skip ends at"
            @change="onClockInput(index, 'end', $event)"
          />

          <input
            class="skip-label"
            :value="segment.label ?? ''"
            placeholder="Intermission"
            maxlength="40"
            aria-label="What the viewer is offered a way past"
            @change="update(index, { label: $event.target.value.trim() || null })"
          />

          <button
            v-if="currentTime !== null"
            type="button"
            class="skip-mini"
            title="Play from here"
            @click="emit('seek', segment.start)"
          >
            Play
          </button>

          <button type="button" class="skip-mini skip-mini-danger" @click="remove(index)">Remove</button>
        </li>
      </ul>

      <p v-else class="skip-hint">Nothing marked. Viewers see no skip button.</p>
    </template>

    <p v-else class="skip-hint">
      The recording has no duration yet, so there is no timeline to mark up.
    </p>
  </div>
</template>

<style scoped>
@reference "../../../css/app.css";

.skip-editor {
  @apply flex flex-col gap-3;
}

.skip-track {
  @apply relative h-10 w-full cursor-crosshair overflow-hidden rounded-lg border border-hairline bg-surface-2;
  touch-action: none;
}

.skip-track.is-full {
  @apply cursor-default;
}

.skip-block {
  @apply absolute inset-y-1 flex cursor-grab items-center justify-center overflow-hidden rounded-md bg-primary-500/70 ring-1 ring-primary-300/60;
}

.skip-block:active {
  @apply cursor-grabbing;
}

.skip-block-label {
  @apply pointer-events-none truncate px-2 text-[11px] font-semibold text-white;
}

.skip-grip {
  @apply absolute inset-y-0 left-0 w-2 cursor-ew-resize bg-primary-200/70;
}

.skip-grip-end {
  @apply left-auto right-0;
}

.skip-playhead {
  @apply pointer-events-none absolute inset-y-0 w-0.5 bg-fg-1;
}

.skip-actions {
  @apply flex flex-wrap items-center gap-3;
}

.skip-btn {
  @apply rounded-lg border border-hairline bg-surface-3 px-3 py-1.5 text-sm font-semibold text-fg-1 transition-colors;
}

.skip-btn:hover:not(:disabled) {
  @apply border-primary-400 text-fg-1;
}

.skip-btn:disabled {
  @apply cursor-not-allowed opacity-50;
}

.skip-hint {
  @apply text-xs text-fg-3;
}

.skip-rows {
  @apply flex flex-col gap-2;
}

.skip-row {
  @apply flex flex-wrap items-center gap-2;
}

.skip-time {
  @apply w-20 rounded-md border border-hairline bg-surface-1 px-2 py-1 text-sm tabular-nums text-fg-1;
}

.skip-dash {
  @apply text-xs text-fg-3;
}

.skip-label {
  @apply min-w-0 flex-1 rounded-md border border-hairline bg-surface-1 px-2 py-1 text-sm text-fg-1;
}

.skip-time:focus,
.skip-label:focus {
  @apply border-primary-400 outline-none;
}

.skip-mini {
  @apply rounded-md border border-hairline bg-surface-3 px-2 py-1 text-xs font-semibold text-fg-2 transition-colors;
}

.skip-mini:hover {
  @apply border-primary-400 text-fg-1;
}

.skip-mini-danger:hover {
  @apply text-fg-1;
  border-color: var(--state-danger);
  background: color-mix(in oklch, var(--state-danger) 30%, transparent);
}
</style>
