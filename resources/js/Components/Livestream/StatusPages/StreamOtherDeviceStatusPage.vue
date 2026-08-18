<template>
  <StatusScreen
    tone="warn"
    eyebrow="Already watching"
    title="End the stream on your other device?"
    subtitle="We allow one stream per account so the bandwidth goes to people who are watching."
  >
    <template #actions>
      <button type="button" class="status-cta" @click="$emit('endStreamOnOtherDevice')">Continue here</button>
    </template>
  </StatusScreen>
</template>

<script setup>
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'

defineEmits(['endStreamOnOtherDevice'])
</script>

<style scoped>
@reference "../../../../css/app.css";

.status-cta {
  @apply inline-flex items-center gap-2 rounded-lg bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}
</style>
