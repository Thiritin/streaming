<template>
  <StatusScreen
    tone="idle"
    :eyebrow="eyebrow"
    title="Stream offline"
    subtitle="The channel is not sending anything right now. This page picks the stream back up on its own."
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
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import UpNextCard from '@/Components/Livestream/StatusPages/Components/UpNextCard.vue'

const props = defineProps({
  show: { type: Object, default: null },
  promoted: { type: Object, default: null },
})

const eyebrow = computed(() => [props.show?.source?.name || props.show?.source, 'off air'].filter(Boolean).join(' · '))
</script>

<style scoped>
@reference "../../../../css/app.css";

.status-cta {
  @apply inline-flex items-center gap-2 rounded-lg bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}
</style>
