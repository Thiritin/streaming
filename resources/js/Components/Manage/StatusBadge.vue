<script setup>
import { computed } from 'vue';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneBadge } from './tones.js';

const props = defineProps({
  /** Status::make() triple from the server: { label, tone, icon } */
  status: { type: Object, default: null },
  uppercase: { type: Boolean, default: true },
});

const classes = computed(() => resolve(toneBadge, props.status?.tone));
</script>

<template>
  <span
    v-if="status"
    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset"
    :class="[classes, uppercase ? 'uppercase tracking-wide' : '']"
  >
    <ManageIcon v-if="status.icon" :name="status.icon" :size="12" />
    {{ status.label }}
  </span>
  <span v-else class="text-fg-3">—</span>
</template>
