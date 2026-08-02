<script setup>
import { ref } from 'vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ChartArea from '@/Components/Manage/ChartArea.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatCard from '@/Components/Manage/StatCard.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  show: { type: Object, required: true },
  /** Null unless the show is live */
  live: { type: Object, default: null },
  report: { type: Object, required: true },
  viewers: { type: Array, default: () => [] },
});

// A live show opens on Live; a finished one has nothing to watch, so it opens on Report.
const tab = ref(props.show.is_live ? 'live' : 'report');

// Only poll while there is something moving.
if (props.show.is_live) {
  usePoll(15000, { only: ['live', 'report', 'viewers'] });
}

const rows = [
  ['Scheduled start', 'scheduled_start'],
  ['Scheduled end', 'scheduled_end'],
  ['Actual start', 'actual_start'],
  ['Actual end', 'actual_end'],
];

const tabClass = (name) =>
  name === tab.value
    ? 'border-state-live/40 bg-state-live/10 text-state-live'
    : 'border-hairline text-fg-2 hover:bg-surface-3';
</script>

<template>
  <ManageLayout>
    <Head :title="`Statistics · ${show.title}`" />

    <PageHeader :title="`Statistics for ${show.title}`" :subtitle="show.source">
      <template #actions>
        <StatusBadge :status="show.status" />
        <Link
          :href="show.edit_url"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="arrow-left" />
          Back to show
        </Link>
      </template>
    </PageHeader>

    <div class="flex flex-col gap-4 p-4">
      <nav class="flex gap-1" aria-label="Statistics view">
        <button
          type="button"
          class="h-7 rounded border px-2.5 text-[12px] transition-colors"
          :class="tabClass('live')"
          :disabled="!live"
          :title="live ? null : 'Only while the show is live'"
          @click="tab = 'live'"
        >
          Live
        </button>
        <button
          type="button"
          class="h-7 rounded border px-2.5 text-[12px] transition-colors"
          :class="tabClass('report')"
          @click="tab = 'report'"
        >
          Report
        </button>
      </nav>

      <!-- Live: what is happening right now -->
      <template v-if="tab === 'live' && live">
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-5">
          <StatCard label="Watching now" :value="live.current" tone="live" />
          <StatCard label="Peak so far" :value="live.peak" tone="info" />
          <StatCard label="Joined" :value="live.joins" tone="ok" hint="last 5 min" />
          <StatCard label="Left" :value="live.leaves" tone="warn" hint="last 5 min" />
          <StatCard label="Open sessions" :value="live.watching" tone="idle" />
        </section>

        <section class="rounded border border-hairline bg-surface-1 p-3">
          <h2 class="mb-2 text-[11px] font-medium uppercase tracking-wide text-fg-2">
            Last 30 minutes
          </h2>
          <ChartArea :points="live.sparkline" :height="120" :show-peak="false" />
        </section>
      </template>

      <!-- Report: what happened across the whole broadcast -->
      <template v-else>
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Peak viewers" :value="report.peak" tone="info" />
          <StatCard label="Average viewers" :value="report.average" tone="info" />
          <StatCard label="Unique viewers" :value="report.unique" tone="info" />
          <StatCard
            label="Watch hours"
            :value="report.watch_hours"
            tone="info"
            :hint="`${report.sampled_minutes} min sampled`"
          />
        </section>

        <section class="rounded border border-hairline bg-surface-1 p-3">
          <h2 class="mb-2 text-[11px] font-medium uppercase tracking-wide text-fg-2">
            Viewers across the broadcast
          </h2>
          <ChartArea :points="report.chart" :height="200" />
        </section>

        <section class="rounded border border-hairline bg-surface-1">
          <h2 class="border-b border-hairline px-3 py-2 text-[12px] font-semibold uppercase tracking-wide text-fg-1">
            Broadcast information
          </h2>
          <dl class="divide-y divide-hairline/60">
            <div v-for="[label, key] in rows" :key="key" class="flex items-center justify-between px-3 py-1.5">
              <dt class="text-[12px] text-fg-2">{{ label }}</dt>
              <dd class="tabular-nums text-[13px] text-fg-1">{{ show[key] ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between px-3 py-1.5">
              <dt class="text-[12px] text-fg-2">Duration</dt>
              <dd class="tabular-nums text-[13px] text-fg-1">{{ show.formatted_duration ?? '—' }}</dd>
            </div>
          </dl>
        </section>
      </template>

      <section class="rounded border border-hairline bg-surface-1">
        <h2 class="border-b border-hairline px-3 py-2 text-[12px] font-semibold uppercase tracking-wide text-fg-1">
          Viewer sessions
          <span class="ml-1 font-normal text-fg-3">last {{ viewers.length }}</span>
        </h2>

        <div v-if="viewers.length" class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b border-hairline bg-surface-2 text-[11px] uppercase tracking-wide text-fg-2">
                <th class="h-7 px-3 text-left">Viewer</th>
                <th class="h-7 px-3 text-left">Joined</th>
                <th class="h-7 px-3 text-left">Left</th>
                <th class="h-7 px-3 text-right">Watched</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="session in viewers" :key="session.id" class="border-b border-hairline/60 last:border-b-0">
                <td class="h-8 px-3">
                  <span v-if="session.active" class="mr-1.5 inline-block size-1.5 rounded-full bg-state-live" />
                  {{ session.name }}
                </td>
                <td class="h-8 px-3 tabular-nums text-fg-2">{{ session.joined_at ?? '—' }}</td>
                <td class="h-8 px-3 tabular-nums text-fg-2">{{ session.left_at ?? 'still watching' }}</td>
                <td class="h-8 px-3 text-right tabular-nums">{{ session.duration }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-else class="px-3 py-6 text-center text-[13px] text-fg-3">Nobody has watched this show yet.</p>
      </section>
    </div>
  </ManageLayout>
</template>
