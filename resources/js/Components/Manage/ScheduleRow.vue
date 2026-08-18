<script setup>
/**
 * Start, duration and end on one line.
 *
 * Duration is the field an operator actually thinks in ("this panel runs 90 minutes"), so
 * it is editable and pushes the end time out; editing either timestamp recomputes the
 * duration instead. Minute precision, matching the programme guide.
 */
import { computed } from 'vue';

const props = defineProps({
  start: { type: String, default: null },
  end: { type: String, default: null },
});

const emit = defineEmits(['update:start', 'update:end']);

const parse = (value) => (value ? new Date(value) : null);

/** `YYYY-MM-DDTHH:mm` in local time, which is what datetime-local expects. */
const format = (date) => {
  const pad = (n) => String(n).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const minutes = computed(() => {
  const from = parse(props.start);
  const to = parse(props.end);

  if (!from || !to) {
    return null;
  }

  return Math.round((to - from) / 60000);
});

const setMinutes = (value) => {
  const from = parse(props.start);
  const length = Number(value);

  if (!from || Number.isNaN(length) || length <= 0) {
    return;
  }

  emit('update:end', format(new Date(from.getTime() + length * 60000)));
};

// Moving the start carries the block with it, so the duration an operator set is kept.
const setStart = (value) => {
  const previous = minutes.value;

  emit('update:start', value);

  const from = parse(value);

  if (from && previous && previous > 0) {
    emit('update:end', format(new Date(from.getTime() + previous * 60000)));
  }
};

const readable = computed(() => {
  if (minutes.value === null) {
    return null;
  }

  if (minutes.value < 0) {
    return 'ends before it starts';
  }

  const hours = Math.floor(minutes.value / 60);
  const rest = minutes.value % 60;

  return hours > 0 ? `${hours}h ${rest ? `${rest}m` : ''}`.trim() : `${rest}m`;
});

const control =
  'h-8 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50';
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <input
      type="datetime-local"
      :value="start"
      :class="[control, 'w-52']"
      aria-label="Scheduled start"
      @input="setStart($event.target.value)"
    />

    <span class="text-fg-3" aria-hidden="true">→</span>

    <label class="inline-flex items-center gap-1.5">
      <input
        type="number"
        min="1"
        step="1"
        :value="minutes"
        :class="[control, 'w-20 tabular-nums']"
        aria-label="Duration in minutes"
        @input="setMinutes($event.target.value)"
      />
      <span class="text-[11px] text-fg-3">min</span>
    </label>

    <span class="text-fg-3" aria-hidden="true">→</span>

    <input
      type="datetime-local"
      :value="end"
      :class="[control, 'w-52']"
      aria-label="Scheduled end"
      @input="$emit('update:end', $event.target.value)"
    />

    <span
      v-if="readable"
      class="text-[11px]"
      :class="minutes < 0 ? 'text-state-danger' : 'text-fg-3'"
    >{{ readable }}</span>
  </div>
</template>
