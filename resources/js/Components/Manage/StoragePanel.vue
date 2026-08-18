<script setup>
import { computed } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneDot, toneText } from './tones.js';

const props = defineProps({
  storage: { type: Object, required: true },
});

// Bands rather than a gradient: an operator acts at "nearly full", not at 61%.
const tone = computed(() => {
  const percent = props.storage.percent;

  if (percent === null || percent === undefined) {
    return 'info';
  }

  if (percent >= 90) return 'danger';
  if (percent >= 75) return 'warn';

  return 'ok';
});

const scanned = computed(() =>
  props.storage.scannedAt
    ? new Date(props.storage.scannedAt).toLocaleString('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
      })
    : null,
);

const barWidth = computed(() => Math.min(100, props.storage.percent ?? 0));

// An unmeasured bucket says nothing at all; a bucket that cannot be read is one line.
const measured = computed(() => !props.storage.error && props.storage.configured);

const objects = (value) => new Intl.NumberFormat('en-GB').format(value ?? 0).replace(/,/g, ' ');

const label = 'text-[11px] font-medium uppercase tracking-wide text-fg-3';
</script>

<template>
  <section
    v-if="storage.error"
    aria-label="Archive storage"
    class="flex h-11 shrink-0 flex-wrap items-center gap-2 border-b border-hairline bg-surface-1 px-4"
  >
    <ManageIcon name="database" :size="13" class="text-state-danger" />
    <span :class="label">Archive storage</span>
    <span class="text-[12px] text-state-danger">{{ storage.error }}</span>
  </section>

  <section v-else-if="measured" aria-label="Archive storage" class="shrink-0 border-b border-hairline bg-surface-1">
    <div class="flex h-11 flex-wrap items-center gap-x-5 gap-y-1 px-4">
      <span class="flex items-center gap-2">
        <ManageIcon name="database" :size="13" :class="resolve(toneText, tone, 'info')" />
        <span :class="label">Archive storage</span>
      </span>

      <span class="flex items-baseline gap-1.5">
        <span :class="label">Used</span>
        <span class="text-[13px] font-semibold tabular-nums" :class="resolve(toneText, tone, 'info')">
          {{ storage.used }}
        </span>
      </span>

      <span class="flex items-baseline gap-1.5">
        <span :class="label">Free</span>
        <span class="text-[13px] tabular-nums text-fg-1">{{ storage.free ?? '—' }}</span>
      </span>

      <span class="flex items-baseline gap-1.5">
        <span :class="label">Capacity</span>
        <span class="text-[13px] tabular-nums text-fg-1">{{ storage.quota ?? '—' }}</span>
        <span v-if="!storage.quota" class="text-[11px] text-fg-3">Set ARCHIVE_QUOTA_BYTES</span>
      </span>

      <span class="flex items-baseline gap-1.5">
        <span :class="label">Objects</span>
        <span class="text-[13px] tabular-nums text-fg-1">{{ objects(storage.objects) }}</span>
      </span>

      <span v-if="storage.percent !== null" class="flex min-w-40 flex-1 items-center gap-2">
        <span class="h-1 flex-1 overflow-hidden rounded-full bg-fg-3/15">
          <span class="block h-full rounded-full" :class="resolve(toneDot, tone, 'info')" :style="{ width: `${barWidth}%` }" />
        </span>
        <span class="text-[11px] tabular-nums text-fg-3">{{ storage.percent }}%</span>
      </span>

      <span v-if="storage.partial" class="text-[11px] text-state-warn">Page cap hit; totals are a floor.</span>
      <span v-if="scanned" class="ml-auto text-[11px] text-fg-3">Measured {{ scanned }}</span>
    </div>

    <ul v-if="storage.prefixes.length" class="border-t border-hairline/60 px-4 py-1">
      <li
        v-for="prefix in storage.prefixes"
        :key="prefix.label"
        class="flex items-center gap-3 py-1"
      >
        <span class="w-56 shrink-0 truncate font-mono text-[11px] text-fg-2">{{ prefix.label }}</span>
        <span class="h-1 flex-1 overflow-hidden rounded-full bg-fg-3/15">
          <span class="block h-full rounded-full bg-state-info" :style="{ width: `${prefix.share}%` }" />
        </span>
        <span class="w-20 shrink-0 text-right text-[12px] tabular-nums text-fg-1">{{ prefix.size }}</span>
        <span class="w-24 shrink-0 text-right text-[11px] tabular-nums text-fg-3">
          {{ objects(prefix.objects) }} objects
        </span>
      </li>
    </ul>
  </section>
</template>
