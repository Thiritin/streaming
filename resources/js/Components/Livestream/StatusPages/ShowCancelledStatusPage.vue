<template>
  <StatusScreen
    tone="danger"
    :eyebrow="eyebrow"
    :title="show.title"
    :subtitle="show.cancellation_reason || 'This show will not be broadcast.'"
    :backdrop="show.thumbnail_url"
  >
    <template #actions>
      <Link v-if="!promoted" :href="route('schedule.index')" class="status-cta">Full schedule</Link>
    </template>

    <template #next>
      <UpNextCard v-if="promoted" :show="promoted" />
    </template>
  </StatusScreen>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import UpNextCard from '@/Components/Livestream/StatusPages/Components/UpNextCard.vue'

const props = defineProps({
  show: { type: Object, required: true },
  promoted: { type: Object, default: null },
})

const eyebrow = computed(() => [props.show.source?.name || props.show.source, 'cancelled'].filter(Boolean).join(' · '))
</script>

<style scoped>
@reference "../../../../css/app.css";

.status-cta {
  @apply inline-flex items-center gap-2 rounded-lg bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}
</style>
