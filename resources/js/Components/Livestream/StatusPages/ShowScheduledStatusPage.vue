<template>
  <StatusScreen
    tone="wait"
    :eyebrow="eyebrow"
    :title="show.title"
    :subtitle="subtitle"
    :backdrop="show.thumbnail_url"
  >
    <div v-if="countdown" class="mt-6 flex items-end justify-center gap-4 sm:gap-6">
      <div v-for="unit in countdown" :key="unit.label" class="flex flex-col items-center">
        <span class="countdown-value">{{ unit.value }}</span>
        <span class="countdown-label">{{ unit.label }}</span>
      </div>
    </div>

    <p v-else class="mt-6 text-lg font-semibold text-white">Starting any moment now</p>

    <p class="mt-4 text-xs text-primary-400">This page starts the stream on its own when the show goes live.</p>

    <template #next>
      <UpNextCard v-if="promoted" :show="promoted" :autoplay="autoplayWhileWaiting" />
    </template>
  </StatusScreen>
</template>

<script setup>
import { computed } from 'vue'
import StatusScreen from '@/Components/Livestream/StatusPages/Components/StatusScreen.vue'
import UpNextCard from '@/Components/Livestream/StatusPages/Components/UpNextCard.vue'
import { useNow } from '@/composables/useNow'

const props = defineProps({
  show: { type: Object, required: true },
  /** Somewhere to go while waiting. Resolved by StreamController::resolvePromotedShow(). */
  promoted: { type: Object, default: null },
})

const now = useNow()

const secondsUntilStart = computed(() => {
  if (!props.show?.scheduled_start) return null

  return Math.floor((new Date(props.show.scheduled_start) - now.value) / 1000)
})

const eyebrow = computed(() => [props.show.source?.name || props.show.source, 'starts soon'].filter(Boolean).join(' · '))

const subtitle = computed(() => {
  if (!props.show?.scheduled_start) return 'Scheduled'

  const start = new Date(props.show.scheduled_start)

  return start.toLocaleString([], {
    weekday: 'long',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
})

const countdown = computed(() => {
  const total = secondsUntilStart.value
  if (total === null || total <= 0) return null

  const days = Math.floor(total / 86400)
  const hours = Math.floor((total % 86400) / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = total % 60

  const pad = (value) => String(value).padStart(2, '0')

  const units = [
    { label: 'days', value: days },
    { label: 'hours', value: hours },
    { label: 'min', value: minutes },
    { label: 'sec', value: seconds },
  ]

  // Leading empty units are noise: "00 days" on a show 20 minutes out reads as broken.
  const first = units.findIndex((unit) => unit.value > 0)

  return units.slice(first === -1 ? 2 : first).map((unit) => ({ ...unit, value: pad(unit.value) }))
})

/*
 * Somebody sitting on a show that starts in two minutes is waiting on purpose, and
 * yanking them to another channel loses them the start they came for. Past ten
 * minutes the wait is long enough that the featured channel is the better offer.
 */
const autoplayWhileWaiting = computed(() => (secondsUntilStart.value ?? 0) > 600)
</script>

<style scoped>
@reference "../../../../css/app.css";

.countdown-value {
  @apply text-3xl font-bold tabular-nums text-white sm:text-5xl;
}

.countdown-label {
  @apply mt-1 text-[11px] font-medium uppercase tracking-[0.16em] text-primary-400;
}
</style>
