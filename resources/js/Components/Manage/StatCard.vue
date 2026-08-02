<script setup>
import { computed } from 'vue';
import { resolve, toneText } from './tones.js';

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [Number, String], required: true },
  tone: { type: String, default: 'info' },
  hint: { type: String, default: null },
});

const display = computed(() =>
  typeof props.value === 'number'
    ? new Intl.NumberFormat('en-GB').format(props.value).replace(/,/g, ' ')
    : props.value,
);
</script>

<template>
  <div class="rounded border border-hairline bg-surface-2 px-3 py-2.5">
    <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">{{ label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums" :class="resolve(toneText, tone, 'info')">
      {{ display }}
    </p>
    <p v-if="hint" class="mt-0.5 text-[11px] text-fg-3">{{ hint }}</p>
  </div>
</template>
