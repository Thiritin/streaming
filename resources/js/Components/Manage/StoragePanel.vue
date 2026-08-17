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
</script>

<template>
  <section aria-label="Archive storage" class="rounded border border-hairline bg-surface-2">
    <header class="flex h-9 items-center gap-2 border-b border-hairline px-3">
      <ManageIcon name="database" :size="14" :class="resolve(toneText, tone, 'info')" />
      <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Archive storage</h2>
      <span v-if="scanned" class="ml-auto text-[11px] text-fg-3">Measured {{ scanned }}</span>
    </header>

    <p v-if="storage.error" class="px-3 py-2.5 text-[12px] text-state-danger">
      {{ storage.error }}
    </p>

    <p v-else-if="!storage.configured" class="px-3 py-2.5 text-[12px] text-fg-2">
      The bucket has not been measured yet. It is scanned hourly, or on demand with
      <span class="font-mono text-[11px] text-fg-1">php artisan archive:usage --refresh</span>.
    </p>

    <div v-else class="flex flex-col gap-3 px-3 py-3">
      <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div>
          <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Used</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums" :class="resolve(toneText, tone, 'info')">
            {{ storage.used }}
          </p>
        </div>
        <div>
          <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Free</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-fg-1">{{ storage.free ?? '—' }}</p>
          <p v-if="!storage.quota" class="mt-0.5 text-[11px] text-fg-3">Set ARCHIVE_QUOTA_BYTES</p>
        </div>
        <div>
          <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Capacity</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-fg-1">{{ storage.quota ?? '—' }}</p>
        </div>
        <div>
          <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">Objects</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-fg-1">
            {{ new Intl.NumberFormat('en-GB').format(storage.objects ?? 0).replace(/,/g, ' ') }}
          </p>
        </div>
      </div>

      <div v-if="storage.percent !== null">
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-fg-3/15">
          <div class="h-full rounded-full" :class="resolve(toneDot, tone, 'info')" :style="{ width: `${barWidth}%` }" />
        </div>
        <p class="mt-1 text-[11px] text-fg-3">{{ storage.percent }}% of capacity in use</p>
      </div>

      <p v-if="storage.partial" class="text-[11px] text-state-warn">
        The listing hit its page cap, so these totals are a floor rather than a total.
      </p>

      <ul v-if="storage.prefixes.length" class="divide-y divide-hairline/60 border-t border-hairline pt-1">
        <li
          v-for="prefix in storage.prefixes"
          :key="prefix.label"
          class="flex items-center gap-3 py-1.5"
        >
          <span class="w-56 shrink-0 truncate font-mono text-[11px] text-fg-2">{{ prefix.label }}</span>
          <span class="h-1 flex-1 overflow-hidden rounded-full bg-fg-3/15">
            <span class="block h-full rounded-full bg-state-info" :style="{ width: `${prefix.share}%` }" />
          </span>
          <span class="w-20 shrink-0 text-right text-[12px] tabular-nums text-fg-1">{{ prefix.size }}</span>
          <span class="w-24 shrink-0 text-right text-[11px] tabular-nums text-fg-3">
            {{ new Intl.NumberFormat('en-GB').format(prefix.objects).replace(/,/g, ' ') }} objects
          </span>
        </li>
      </ul>
    </div>
  </section>
</template>
