<script setup>
/**
 * One series over time, as inline SVG.
 *
 * No chart library: this is one series against one axis, and a dependency would cost more
 * than the 60 lines of path maths. Gaps in the data (nothing recorded that minute) break
 * the line rather than interpolating across them, so a dropout reads as a dropout.
 *
 * Hovering reads a single sample back - the point of keeping history is being able to ask
 * what a box was doing at 21:40, and a shape alone cannot answer that.
 */
import { computed, ref } from 'vue';
import { resolve, toneText, toneFill, toneFillSolid } from './tones.js';

const props = defineProps({
  /** [{ label, at?, value }] in time order; value null means "no sample" */
  points: { type: Array, default: () => [] },
  height: { type: Number, default: 160 },
  /** Marks the peak with a dot and a label. */
  showPeak: { type: Boolean, default: true },
  /** count | percent | bytes | bitrate | decimal */
  unit: { type: String, default: 'count' },
  tone: { type: String, default: 'live' },
  emptyMessage: {
    type: String,
    default: 'No samples recorded yet. Statistics are written once a minute while a show is live.',
  },
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

  return { index, value: highest, label: props.points[index]?.at ?? props.points[index]?.label };
});

/** Up to six evenly spaced time labels, so the axis never crowds. */
const ticks = computed(() => {
  const step = Math.max(1, Math.ceil(props.points.length / 6));

  return props.points
    .map((point, index) => ({ ...point, index }))
    .filter((point) => point.index % step === 0);
});

const number = (value, decimals = 0) =>
  new Intl.NumberFormat('en-GB', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
    .format(value)
    .replace(/,/g, ' ');

/**
 * Binary units for stored bytes, decimal units for link speed, because that is how each
 * is quoted everywhere else - a disk is GiB, an uplink is Gbit/s.
 */
const scale = (value, step, units) => {
  const magnitude = value > 0 ? Math.min(Math.floor(Math.log(value) / Math.log(step)), units.length - 1) : 0;
  const scaled = value / step ** magnitude;

  return `${number(scaled, magnitude > 1 && scaled < 100 ? 1 : 0)} ${units[magnitude]}`;
};

const format = (value) => {
  if (value === null || value === undefined) {
    return '—';
  }

  switch (props.unit) {
    case 'percent':
      return `${number(value, value < 10 ? 1 : 0)}%`;
    case 'bytes':
      return scale(value, 1024, ['B', 'KiB', 'MiB', 'GiB', 'TiB']);
    case 'bitrate':
      return scale(value * 8, 1000, ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s']);
    case 'decimal':
      return number(value, 2);
    default:
      return number(value);
  }
};

const stroke = computed(() => resolve(toneText, props.tone, 'live'));
const fill = computed(() => resolve(toneFill, props.tone, 'live'));
const dot = computed(() => resolve(toneFillSolid, props.tone, 'live'));

// Hover readout. The index comes from the pointer's position across the plot, so it works
// the same whether the chart is 300px or 1200px wide.
const plot = ref(null);
const hovered = ref(null);

const track = (event) => {
  if (!props.points.length || !plot.value) {
    return;
  }

  const box = plot.value.getBoundingClientRect();
  const ratio = Math.min(Math.max((event.clientX - box.left) / box.width, 0), 1);

  hovered.value = Math.round(ratio * (props.points.length - 1));
};

const hoveredPoint = computed(() => (hovered.value === null ? null : props.points[hovered.value] ?? null));
</script>

<template>
  <div v-if="points.length" class="flex flex-col gap-1">
    <div class="flex items-start gap-2">
      <!-- Value axis: only ever three labels, which is enough to read a shape by. -->
      <div
        class="flex w-16 shrink-0 flex-col justify-between text-right text-[10px] tabular-nums text-fg-3"
        :style="{ height: `${height}px` }"
      >
        <span>{{ format(max) }}</span>
        <span>{{ format(max / 2) }}</span>
        <span>{{ format(0) }}</span>
      </div>

      <div ref="plot" class="relative min-w-0 flex-1" @mousemove="track" @mouseleave="hovered = null">
        <svg
          class="w-full"
          :viewBox="`0 0 ${W} ${height}`"
          :style="{ height: `${height}px` }"
          preserveAspectRatio="none"
          role="img"
          :aria-label="`${unit} over time`"
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

          <path v-for="(area, index) in areas" :key="`area-${index}`" :d="area" :class="fill" />

          <polyline
            v-for="(run, index) in segments"
            :key="`line-${index}`"
            :points="run.join(' ')"
            fill="none"
            stroke="currentColor"
            :class="stroke"
            stroke-width="2"
            vector-effect="non-scaling-stroke"
            stroke-linejoin="round"
          />

          <circle
            v-if="peak"
            :cx="x(peak.index)"
            :cy="y(peak.value)"
            r="3"
            :class="dot"
            vector-effect="non-scaling-stroke"
          />

          <template v-if="hoveredPoint">
            <line
              :x1="x(hovered)"
              y1="0"
              :x2="x(hovered)"
              :y2="height"
              stroke="currentColor"
              class="text-fg-3"
              stroke-width="1"
              vector-effect="non-scaling-stroke"
            />
            <circle
              v-if="hoveredPoint.value !== null"
              :cx="x(hovered)"
              :cy="y(hoveredPoint.value)"
              r="3.5"
              :class="dot"
              vector-effect="non-scaling-stroke"
            />
          </template>
        </svg>

        <!-- Pinned to a corner rather than following the cursor: it never covers the part
             of the line being read, and it cannot escape the container. -->
        <div
          v-if="hoveredPoint"
          class="pointer-events-none absolute right-1 top-1 rounded border border-hairline bg-surface-2 px-2 py-1 text-[11px] shadow"
        >
          <p class="tabular-nums text-fg-1">{{ format(hoveredPoint.value) }}</p>
          <p class="text-fg-3">{{ hoveredPoint.at ?? hoveredPoint.label }}</p>
        </div>
      </div>
    </div>

    <div class="flex justify-between pl-[72px] text-[10px] tabular-nums text-fg-3">
      <span v-for="tick in ticks" :key="tick.index">{{ tick.label }}</span>
    </div>

    <p v-if="peak" class="pl-[72px] text-[11px] text-fg-3">Peak {{ format(peak.value) }} at {{ peak.label }}</p>
  </div>

  <p v-else class="py-8 text-center text-[13px] text-fg-3">{{ emptyMessage }}</p>
</template>
