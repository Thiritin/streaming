<template>
  <StatusScreen
    tone="wait"
    :eyebrow="eyebrow"
    title="Starting soon"
    subtitle="The channel is warming up. Playback starts here as soon as the first frames land."
    :backdrop="show?.thumbnail_url"
  >
    <div class="mt-6 flex items-center justify-center gap-1.5" aria-hidden="true">
      <span class="wait-dot" />
      <span class="wait-dot [animation-delay:200ms]" />
      <span class="wait-dot [animation-delay:400ms]" />
    </div>
  </StatusScreen>
</template>

<script setup>
import { computed } from 'vue'
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'

const props = defineProps({
  show: { type: Object, default: null },
})

const eyebrow = computed(() => [props.show?.source?.name || props.show?.source, 'starting soon'].filter(Boolean).join(' · '))
</script>

<style scoped>
@reference "../../../../css/app.css";

.wait-dot {
  @apply h-2 w-2 rounded-full bg-primary-300;
  animation: blink 1.4s ease-in-out infinite;
}
</style>
