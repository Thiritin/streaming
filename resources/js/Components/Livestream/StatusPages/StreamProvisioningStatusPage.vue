<template>
  <StatusScreen
    tone="wait"
    eyebrow="Capacity"
    title="Finding you a server"
    subtitle="We are adding capacity for this stream. This takes a couple of minutes at most."
    :backdrop="show?.thumbnail_url"
  >
    <FaCircleNotchIcon class="mx-auto mt-6 block animate-spin fill-current text-[3rem] text-primary-300" />

    <p class="mt-4 text-xs text-primary-400">You are sent through to the stream the moment a server is free.</p>
  </StatusScreen>
</template>

<script setup>
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import FaCircleNotchIcon from '@/Components/Icons/FaCircleNotchIcon.vue'

defineProps({
  show: { type: Object, default: null },
})
</script>
