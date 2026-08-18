<template>
  <StatusScreen
    tone="warn"
    eyebrow="Technical issues"
    title="The stream is having trouble"
    subtitle="Stand by. This page picks the stream back up on its own once it is healthy."
    :backdrop="show?.thumbnail_url"
  >
    <p v-if="listeners > 0" class="mt-4 text-sm text-primary-300">
      <span class="font-semibold text-white tabular-nums">{{ listeners }}</span>
      {{ listeners === 1 ? 'person is' : 'people are' }} waiting with you.
    </p>

    <template #next>
      <UpNextCard v-if="promoted" :show="promoted" :autoplay="false" />
    </template>
  </StatusScreen>
</template>

<script setup>
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import UpNextCard from '@/Components/Livestream/StatusPages/Components/UpNextCard.vue'

defineProps({
  listeners: { type: Number, default: 0 },
  show: { type: Object, default: null },
  promoted: { type: Object, default: null },
})
</script>
