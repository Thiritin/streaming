<template>
  <StatusScreen
    tone="wait"
    eyebrow="Connection"
    title="Reconnecting"
    subtitle="The stream dropped out. Getting it back, usually within a few seconds."
    :backdrop="show?.thumbnail_url"
  >
    <FaArrowPathIcon class="mx-auto mt-6 block animate-spin fill-current text-[3rem] text-primary-300" />
  </StatusScreen>
</template>

<script setup>
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import FaArrowPathIcon from '@/Components/Icons/FaArrowPathIcon.vue'

defineProps({
  show: { type: Object, default: null },
})
</script>
