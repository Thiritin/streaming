<template>
  <div v-if="show" class="up-next group">
    <!-- The whole card is the link. A card with one destination does not need a
         button inside it, and a target the size of the card is a far easier hit on
         a phone or a TV remote than a 100px button in the corner. Anything that is
         not that destination (the cancel) sits above this overlay. -->
    <Link :href="href" class="absolute inset-0 z-10" :aria-label="`Watch ${show.title}`" />

    <div class="flex items-center gap-3 p-3 sm:gap-4 sm:p-4">
      <div class="up-next-thumb">
        <img
          v-if="show.thumbnail_url"
          :src="show.thumbnail_url"
          :alt="show.title"
          class="h-full w-full object-cover transition-transform duration-(--dur-base) group-hover:scale-105"
        />
        <TilePlaceholder v-else :label="show.source" />
      </div>

      <div class="min-w-0 flex-1 text-left">
        <p class="up-next-label">{{ label }}</p>

        <h2 class="truncate text-base font-semibold text-white sm:text-lg">{{ show.title }}</h2>

        <p class="mt-0.5 truncate text-xs text-primary-300 sm:text-sm">{{ meta }}</p>
      </div>

      <span v-if="show.is_live" class="up-next-live">
        <span class="live-pip" aria-hidden="true" />
        Live
      </span>

      <!-- Hidden on a phone: the whole card is already the target there, and the
           chevron only squeezes the title into two lines. -->
      <svg
        class="hidden h-5 w-5 shrink-0 text-primary-400 transition-[transform,color] duration-(--dur-base) group-hover:translate-x-0.5 group-hover:text-white sm:block"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
      </svg>
    </div>

    <div v-if="counting" class="relative z-20 flex items-center justify-between gap-3 px-3 pb-3 sm:px-4 sm:pb-4">
      <p class="text-xs text-primary-300 tabular-nums" aria-live="polite">Playing in {{ remaining }}s</p>
      <button type="button" class="up-next-ghost" @click="cancel">Stay here</button>
    </div>

    <!-- The countdown as a line rather than a number alone: it reads at a glance from
         across a room, which is where this screen is usually being watched from. -->
    <div v-if="counting" class="h-1 w-full bg-white/10">
      <div class="h-full bg-primary-300 transition-[width] duration-1000 ease-linear" :style="{ width: `${progress * 100}%` }" />
    </div>
  </div>
</template>

<script setup>
import { computed, toRef } from 'vue'
import { Link } from '@inertiajs/vue3'
import TilePlaceholder from '@/Components/TilePlaceholder.vue'
import { useAutoplayNext } from '@/composables/useAutoplayNext'
import { useNow } from '@/composables/useNow'

const props = defineProps({
  /** Promoted show from StreamController::resolvePromotedShow(). */
  show: { type: Object, default: null },
  /** Count down to the visit. Ignored for a target that is not live. */
  autoplay: { type: Boolean, default: true },
  seconds: { type: Number, default: 12 },
})

const now = useNow(30000)

const { remaining, progress, counting, cancel } = useAutoplayNext(toRef(props, 'show'), {
  seconds: props.seconds,
  enabled: props.autoplay,
})

const href = computed(() => (props.show?.slug ? `/show/${props.show.slug}` : '/'))

const label = computed(() => {
  if (!props.show) return ''
  if (props.show.is_live) return props.show.is_primary_channel ? 'Watch instead' : 'Live now'

  return 'Up next'
})

const meta = computed(() => {
  const show = props.show
  if (!show) return ''

  const parts = [show.source].filter(Boolean)

  if (show.is_live && show.viewer_count) {
    parts.push(`${formatViewers(show.viewer_count)} watching`)
  } else if (!show.is_live && show.scheduled_start) {
    parts.push(startsLabel(show.scheduled_start))
  }

  return parts.join(' · ')
})

const formatViewers = (count) => {
  if (count >= 1000000) return `${(count / 1000000).toFixed(1)}M`
  if (count >= 1000) return `${(count / 1000).toFixed(1)}K`

  return String(count)
}

const startsLabel = (value) => {
  const start = new Date(value)
  const diff = Math.floor((start - now.value) / 1000)

  if (diff <= 0) return 'starting shortly'
  if (diff < 3600) return `in ${Math.ceil(diff / 60)} min`

  const clock = start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })

  if (diff < 86400) return `today at ${clock}`

  return `${start.toLocaleDateString([], { weekday: 'short' })} at ${clock}`
}
</script>

<style scoped>
@reference "../../../../../css/app.css";

.up-next {
  @apply relative w-full max-w-lg overflow-hidden rounded-xl bg-black/55 ring-1 ring-white/10 backdrop-blur-md transition-[background-color,box-shadow] duration-(--dur-base);
}

.up-next:hover,
.up-next:focus-within {
  @apply bg-black/75 ring-white/25;
}

.up-next-thumb {
  @apply relative aspect-video w-24 shrink-0 overflow-hidden rounded-lg bg-primary-800 ring-1 ring-white/10 sm:w-32;
}

.up-next-label {
  @apply whitespace-nowrap text-[11px] font-semibold uppercase tracking-[0.16em] text-primary-300;
}

.up-next-live {
  @apply inline-flex shrink-0 items-center gap-1.5 rounded bg-red-600 px-2 py-1 text-[11px] font-bold uppercase tracking-wider text-white;
}

.up-next-ghost {
  @apply rounded-lg px-3 py-1.5 text-xs font-medium text-primary-300 transition-colors hover:bg-white/10 hover:text-white;
}

.up-next-ghost:focus-visible {
  @apply outline-none ring-2 ring-primary-300;
}

.live-pip {
  @apply h-1.5 w-1.5 rounded-full bg-white;
  animation: blink 1.8s ease-in-out infinite;
}
</style>
