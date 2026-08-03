<script setup>
/**
 * Viewers over time, as inline SVG.
 *
 * No chart library: this is one series against one axis, and a dependency would cost more
 * than the 60 lines of path maths. Gaps in the data (nothing recorded that minute) break
 * the line rather than interpolating across them, so a dropout reads as a dropout.
 */
import { computed } from 'vue';

const props = defineProps({
  /** [{ label, value }] in time order; value null means "no sample" */
  points: { type: Array, default: () => [] },
  height: { type: Number, default: 160 },
  /** Marks the peak with a dot and a label. */
  showPeak: { type: Boolean, default: true },
});

const W = 1000;

const values = computed(() => props.points.map((point) => point.value).filter((value) => value !== null));

const max = computed(() => {
  const highest = values.value.length ? Math.max(...values.value) : 0;

  // A flat zero series still needs a scale, and a little headroom keeps the peak off the
  // top edge where it would be clipped.
  return highest > 0 ? highest * 1.15 : 1;
});

const x = (index) => (props.points.length <= 1 ? 0 : (index / (props.points.length - 1)) * W);
const y = (value) => props.height - (value / max.value) * props.height;

/** One path per unbroken run of samples. */
const segments = computed(() => {
  const runs = [];
  let current = [];

  props.points.forEach((point, index) => {
    if (point.value === null) {
      if (current.length) {
        runs.push(current);
        current = [];
      }

      return;
    }

    current.push(`${x(index)},${y(point.value)}`);
  });

  if (current.length) {
    runs.push(current);
  }

  return runs;
});

const areas = computed(() =>
  segments.value
    .filter((run) => run.length > 1)
    .map((run) => {
      const first = run[0].split(',')[0];
      const last = run[run.length - 1].split(',')[0];

      return `M${first},${props.height} L${run.join(' L')} L${last},${props.height} Z`;
    }),
);

const peak = computed(() => {
  if (!props.showPeak || !values.value.length) {
    return null;
  }

  const highest = Math.max(...values.value);
  const index = props.points.findIndex((point) => point.value === highest);

  return { index, value: highest, label: props.points[index]?.label };
});

/** Up to six evenly spaced time labels, so the axis never crowds. */
const ticks = computed(() => {
  const step = Math.max(1, Math.ceil(props.points.length / 6));

  return props.points
    .map((point, index) => ({ ...point, index }))
    .filter((point) => point.index % step === 0);
});

const format = (value) => new Intl.NumberFormat('en-GB').format(value).replace(/,/g, ' ');
</script>

<template>
  <div v-if="points.length" class="flex flex-col gap-1">
    <div class="flex items-start gap-2">
      <!-- Value axis: only ever three labels, which is enough to read a shape by. -->
      <div
        class="flex w-12 shrink-0 flex-col justify-between text-right text-[10px] tabular-nums text-fg-3"
        :style="{ height: `${height}px` }"
      >
        <span>{{ format(Math.round(max)) }}</span>
        <span>{{ format(Math.round(max / 2)) }}</span>
        <span>0</span>
      </div>

      <svg
        class="min-w-0 flex-1"
        :viewBox="`0 0 ${W} ${height}`"
        :style="{ height: `${height}px` }"
        preserveAspectRatio="none"
        role="img"
        aria-label="Viewers over time"
      >
        <line
          v-for="fraction in [0, 0.5, 1]"
          :key="fraction"
          x1="0"
          :y1="height * fraction"
          :x2="W"
          :y2="height * fraction"
          stroke="currentColor"
          class="text-hairline"
          stroke-width="1"
          vector-effect="non-scaling-stroke"
        />

        <path
          v-for="(area, index) in areas"
          :key="`area-${index}`"
          :d="area"
          class="fill-state-live/15"
        />

        <polyline
          v-for="(run, index) in segments"
          :key="`line-${index}`"
          :points="run.join(' ')"
          fill="none"
          stroke="currentColor"
          class="text-state-live"
          stroke-width="2"
          vector-effect="non-scaling-stroke"
          stroke-linejoin="round"
        />

        <circle
          v-if="peak"
          :cx="x(peak.index)"
          :cy="y(peak.value)"
          r="3"
          class="fill-state-live"
          vector-effect="non-scaling-stroke"
        />
      </svg>
    </div>

    <div class="flex justify-between pl-14 text-[10px] tabular-nums text-fg-3">
      <span v-for="tick in ticks" :key="tick.index">{{ tick.label }}</span>
    </div>

    <p v-if="peak" class="pl-14 text-[11px] text-fg-3">
      Peak {{ format(peak.value) }} at {{ peak.label }}
    </p>
  </div>

  <p v-else class="py-8 text-center text-[13px] text-fg-3">
    No samples recorded yet. Statistics are written once a minute while a show is live.
  </p>
</template>
