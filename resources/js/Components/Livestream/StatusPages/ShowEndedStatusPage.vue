<template>
  <StatusScreen
    tone="idle"
    :eyebrow="eyebrow"
    :title="show.title"
    subtitle="That's a wrap. Thanks for watching."
    :backdrop="show.thumbnail_url"
  >
    <p v-if="stats.length" class="mt-3 text-xs text-primary-400">
      {{ stats.join(' · ') }}
    </p>

    <template #actions>
      <Link v-if="!promoted" :href="route('shows.index')" class="status-cta">Browse shows</Link>
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
  /**
   * Where to send someone whose show has ended: the featured channel if it is on air,
   * otherwise the busiest live show, otherwise what is on next. Resolved server side
   * by StreamController::resolvePromotedShow().
   */
  promoted: { type: Object, default: null },
})

const eyebrow = computed(() => [props.show.source?.name || props.show.source, 'ended'].filter(Boolean).join(' · '))

const stats = computed(() => {
  const out = []
  const { actual_start: start, actual_end: end, peak_viewer_count: peak } = props.show

  if (start && end) {
    const minutes = Math.max(1, Math.round((new Date(end) - new Date(start)) / 60000))
    const hours = Math.floor(minutes / 60)
    out.push(hours > 0 ? `Ran ${hours}h ${minutes % 60}m` : `Ran ${minutes}m`)
  }

  if (peak) {
    out.push(`Peaked at ${peak} watching`)
  }

  return out
})
</script>

<style scoped>
@reference "../../../../css/app.css";

.status-cta {
  @apply inline-flex items-center gap-2 rounded-lg bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}
</style>
