<template>
  <StatusScreen
    tone="danger"
    eyebrow="Connection interrupted"
    title="The channel stopped sending"
    subtitle="This is on our side, not yours. Playback resumes here as soon as the feed is back."
    :backdrop="show?.thumbnail_url"
  >
    <template #actions>
      <Link v-if="!promoted" :href="route('shows.index')" class="status-cta">Browse shows</Link>
    </template>

    <template #next>
      <UpNextCard v-if="promoted" :show="promoted" :autoplay="false" />
    </template>
  </StatusScreen>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import UpNextCard from '@/Components/Livestream/StatusPages/Components/UpNextCard.vue'

defineProps({
  show: { type: Object, default: null },
  promoted: { type: Object, default: null },
})
</script>

<style scoped>
@reference "../../../../css/app.css";

.status-cta {
  @apply inline-flex items-center gap-2 rounded-lg bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}
</style>
