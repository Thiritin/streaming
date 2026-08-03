<template>
  <div class="min-h-screen">
    <Head title="Schedule" />

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-8 pb-4">
      <div class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300">Programme guide</p>
        <h1 class="text-3xl font-bold text-white tracking-tight">What's on and what's next</h1>
        <p class="text-primary-300 text-sm max-w-2xl">
          Everything in the order it airs, across all channels. The channel travels with each row.
        </p>
      </div>

      <!-- Day picker -->
      <div v-if="days.length" class="mt-6 flex gap-2 overflow-x-auto scrollbar-none">
        <button
          v-for="day in days"
          :key="day.date"
          type="button"
          class="day-chip"
          :class="{ 'day-chip-active': activeDate === day.date }"
          @click="activeDate = day.date"
        >
          {{ day.label }}
          <span v-if="day.label === 'Today'" class="text-[11px] opacity-60">{{ day.sub_label }}</span>
        </button>
      </div>
    </div>

    <div v-if="agenda.length" class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pb-14">
      <ol class="agenda">
        <template v-for="(entry, index) in agenda" :key="entry.id">
          <!-- Marker drops in front of the first row that has not started yet -->
          <li v-if="showNowMarkerBefore(index)" class="now-marker">
            <span class="now-marker-label tabular-nums">{{ nowClock }}</span>
          </li>

          <li>
            <Link :href="route('show.view', entry.slug)" class="agenda-row" :class="rowClass(entry)">
              <span class="agenda-time">
                <span class="agenda-start tabular-nums">{{ formatClock(entry.scheduled_start) }}</span>
                <span class="agenda-end tabular-nums">{{ formatClock(entry.scheduled_end) }}</span>
              </span>

              <span class="agenda-main">
                <span class="agenda-title">{{ entry.title }}</span>
                <span class="agenda-meta">
                  <span class="channel-badge" :class="{ 'channel-badge-primary': entry.channel === primaryChannel }">
                    {{ entry.channel }}
                  </span>
                  <span class="tabular-nums">{{ entry.durationLabel }}</span>
                  <span v-if="entry.is_restricted" class="agenda-restricted">Restricted</span>
                  <span
                    v-if="entry.will_be_available"
                    class="agenda-available"
                    title="This show is planned to be published afterwards, so you can watch it later if you miss it live."
                  >Available later</span>
                </span>
              </span>

              <span class="agenda-status">
                <span v-if="entry.status === 'live'" class="status-live">
                  <span class="live-pip" aria-hidden="true" />
                  On air
                </span>
                <span v-else-if="entry.status === 'ended'" class="status-muted">Ended</span>
                <span v-else-if="startsWithin(entry, 30)" class="status-soon">{{ countdown(entry) }}</span>
                <span v-else class="status-muted">{{ countdown(entry) }}</span>
              </span>
            </Link>
          </li>
        </template>
      </ol>
    </div>

    <div v-else class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 py-20 text-center">
      <h2 class="text-xl font-semibold text-white">Nothing scheduled yet</h2>
      <p class="text-primary-400 mt-2">Shows appear here as soon as they land in the schedule.</p>
    </div>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useNow } from '@/composables/useNow';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  days: { type: Array, default: () => [] },
  primaryChannel: { type: String, default: null },
  currentTime: { type: String, required: false },
});

const now = useNow();
const activeDate = ref(props.days.find((day) => day.is_today)?.date ?? props.days[0]?.date ?? null);

const activeDay = computed(() => props.days.find((day) => day.date === activeDate.value) ?? null);

// One chronological list across every channel; the channel rides along as a badge
// rather than owning a column, which reads better on a phone and on a quiet day.
const agenda = computed(() => {
  const rows = (activeDay.value?.channels ?? []).flatMap((channel) =>
    channel.shows.map((show) => ({
      ...show,
      channel: channel.name,
      durationLabel: durationLabel(show.scheduled_start, show.scheduled_end),
    }))
  );

  return rows.sort((a, b) => new Date(a.scheduled_start) - new Date(b.scheduled_start));
});

const nowClock = computed(() => new Date(now.value).toLocaleTimeString([], {
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
}));

const isToday = computed(() => Boolean(activeDay.value?.is_today));

const showNowMarkerBefore = (index) => {
  if (!isToday.value) return false;

  const entry = agenda.value[index];
  if (new Date(entry.scheduled_start).getTime() <= now.value) return false;

  const previous = agenda.value[index - 1];

  return !previous || new Date(previous.scheduled_start).getTime() <= now.value;
};

const rowClass = (entry) => {
  if (entry.status === 'live') return 'agenda-row-live';
  if (entry.status === 'ended' || new Date(entry.scheduled_end).getTime() < now.value) return 'agenda-row-past';

  return '';
};

const startsWithin = (entry, minutes) => {
  const diff = (new Date(entry.scheduled_start).getTime() - now.value) / 60000;

  return diff >= 0 && diff <= minutes;
};

const countdown = (entry) => {
  const diff = Math.round((new Date(entry.scheduled_start).getTime() - now.value) / 60000);

  if (diff < 0) return 'Started';
  if (diff === 0) return 'Now';
  if (diff < 60) return `in ${diff}m`;

  const hours = Math.floor(diff / 60);
  const rest = diff % 60;

  return rest ? `in ${hours}h ${rest}m` : `in ${hours}h`;
};

const durationLabel = (start, end) => {
  const minutes = Math.max(1, Math.round((new Date(end) - new Date(start)) / 60000));
  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  return rest ? `${hours}h ${rest}m` : `${hours}h`;
};

const formatClock = (value) => new Date(value).toLocaleTimeString([], {
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});
</script>

<style scoped>
@reference "../../css/app.css";

.agenda {
  @apply m-0 flex list-none flex-col gap-2 p-0;
}

.agenda-row {
  @apply grid grid-cols-[64px_minmax(0,1fr)] items-center gap-x-4 gap-y-1 rounded-xl bg-primary-950/45 px-4 py-3 ring-1 ring-white/5 transition-colors sm:grid-cols-[72px_minmax(0,1fr)_auto];
}

.agenda-row:hover {
  @apply bg-primary-800/50 ring-white/10;
}

.agenda-row:focus-visible {
  @apply outline-none ring-2 ring-primary-400;
}

/* Live rows carry the only accent in the list, so "on air now" is findable
   without reading a single time. */
.agenda-row-live {
  @apply bg-primary-500/15 ring-primary-400/40;
}

.agenda-row-past {
  @apply opacity-55;
}

.agenda-time {
  @apply flex flex-col leading-tight;
}

.agenda-start {
  @apply text-sm font-semibold text-white;
}

.agenda-end {
  @apply text-[11px] text-primary-400;
}

.agenda-main {
  @apply flex min-w-0 flex-col gap-1;
}

.agenda-title {
  @apply truncate text-sm font-semibold text-white;
}

.agenda-meta {
  @apply flex flex-wrap items-center gap-2 text-[11px] text-primary-400;
}

.channel-badge {
  @apply rounded-full border border-primary-700 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.1em] text-primary-200;
}

.channel-badge-primary {
  @apply border-primary-400/60 bg-primary-500/15 text-primary-100;
}

.agenda-restricted {
  @apply rounded border border-primary-700 px-1.5 py-0.5;
}

/* Softer than the restricted badge: this one is reassurance, not a warning. */
.agenda-available {
  @apply rounded border border-primary-800 px-1.5 py-0.5 text-primary-300;
}

.agenda-status {
  @apply col-span-2 justify-self-start text-xs sm:col-span-1 sm:justify-self-end;
}

.status-live {
  @apply inline-flex items-center gap-1.5 rounded-full bg-red-600 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider text-white;
}

.status-soon {
  @apply inline-flex items-center rounded-full bg-orange-500/20 px-2.5 py-1 text-[11px] font-semibold tabular-nums text-orange-300;
}

.status-muted {
  @apply text-[11px] tabular-nums text-primary-400;
}

.live-pip {
  @apply h-1.5 w-1.5 rounded-full bg-white;
  animation: blink 1.8s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
  .live-pip {
    animation: none;
  }
}

.now-marker {
  @apply relative my-2 h-px bg-primary-400/50;
}

.now-marker-label {
  @apply absolute -top-2 left-0 rounded bg-primary-400 px-1.5 py-0.5 text-[10px] font-bold text-primary-950;
}

.day-chip {
  @apply inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-primary-700/70 px-4 py-1.5 text-sm font-medium text-primary-200 transition-colors;
}

/* :not() keeps hover from repainting the selected chip white-on-white. */
.day-chip:hover:not(.day-chip-active) {
  @apply border-primary-500 text-white;
}

.day-chip-active {
  @apply border-white bg-white font-semibold text-primary-950;
}

.day-chip:focus-visible {
  @apply outline-none ring-2 ring-primary-400 ring-offset-2 ring-offset-primary-900;
}

.scrollbar-none {
  scrollbar-width: none;
}

.scrollbar-none::-webkit-scrollbar {
  display: none;
}
</style>
