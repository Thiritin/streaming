<script setup>
/**
 * One screen for the maintainer and the producer: what is broken, how much headroom is
 * left, how many people are watching, and what happens next.
 *
 * Polls only the props that move. The page body is never replaced wholesale, so a
 * scrolled-down alert list stays where the operator left it.
 */
import { computed } from 'vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatCard from '@/Components/Manage/StatCard.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';
import { resolve, toneDot, toneText } from '@/Components/Manage/tones.js';

const props = defineProps({
  capacity: { type: Array, default: () => [] },
  edgeServers: { type: Array, default: () => [] },
  viewers: { type: Object, default: () => ({ total: 0, peak: 0, perSource: [] }) },
  servers: { type: Array, default: () => [] },
  alerts: { type: Array, default: () => [] },
  schedule: { type: Array, default: () => [] },
  scheduleHours: { type: Number, default: 6 },
});

// 5s: fast enough that a viewer count feels live, slow enough for a room full of tabs.
usePoll(5000, { only: ['capacity', 'edgeServers', 'viewers', 'servers', 'alerts', 'schedule'] });

const number = (value) => new Intl.NumberFormat('en-GB').format(value ?? 0).replace(/,/g, ' ');

const cards = computed(() => [
  { label: 'Viewers now', value: props.viewers.total, tone: props.viewers.total > 0 ? 'live' : 'idle' },
  { label: 'Peak this show', value: props.viewers.peak, tone: 'info' },
  ...props.capacity,
  ...props.edgeServers,
]);

const worst = computed(() => props.alerts.find((alert) => alert.tone === 'danger') ?? null);

const loadTone = (load) => {
  if (load === null) {
    return 'idle';
  }

  if (load >= 90) {
    return 'danger';
  }

  return load >= 70 ? 'warn' : 'ok';
};

const cell = 'h-8 px-3 text-[13px]';
const head = 'h-7 px-3 text-left text-[11px] font-medium uppercase tracking-wide text-fg-2';
</script>

<template>
  <ManageLayout>
    <Head title="Dashboard" />

    <PageHeader
      title="Dashboard"
      :subtitle="worst ? worst.title : 'Nothing is reporting a fault right now.'"
    />

    <div class="flex flex-col gap-4 p-4">
      <section aria-label="Numbers" class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard
          v-for="card in cards"
          :key="card.label"
          :label="card.label"
          :value="card.value"
          :tone="card.tone"
          :hint="card.hint ?? null"
        />
      </section>

      <section aria-label="Alerts" class="rounded border border-hairline bg-surface-2">
        <header class="flex h-9 items-center gap-2 border-b border-hairline px-3">
          <ManageIcon name="triangle-alert" :size="14" :class="alerts.length ? 'text-state-danger' : 'text-fg-3'" />
          <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Alerts</h2>
          <span v-if="alerts.length" class="text-[11px] tabular-nums text-fg-2">{{ alerts.length }}</span>
        </header>

        <ul v-if="alerts.length" class="divide-y divide-hairline/60">
          <li v-for="(alert, index) in alerts" :key="`${alert.title}-${index}`" class="flex items-start gap-2.5 px-3 py-2">
            <span class="mt-1.5 size-1.5 shrink-0 rounded-full" :class="resolve(toneDot, alert.tone)" />
            <div class="min-w-0">
              <p class="text-[13px]" :class="resolve(toneText, alert.tone)">
                <Link v-if="alert.url" :href="alert.url" class="hover:underline">{{ alert.title }}</Link>
                <span v-else>{{ alert.title }}</span>
              </p>
              <p v-if="alert.detail" class="text-[11px] text-fg-3">{{ alert.detail }}</p>
            </div>
          </li>
        </ul>
        <p v-else class="px-3 py-2 text-[13px] text-fg-3">
          All sources online, all servers healthy, capacity within limits.
        </p>
      </section>

      <div class="grid gap-4 xl:grid-cols-2">
        <section aria-label="Servers" class="rounded border border-hairline bg-surface-2">
          <header class="flex h-9 items-center gap-2 border-b border-hairline px-3">
            <ManageIcon name="server" :size="14" class="text-fg-2" />
            <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Servers</h2>
          </header>

          <!-- Below md the same rows stack: six columns on a phone is a sideways scroll
               with the hostname off the left edge. -->
          <ul class="divide-y divide-hairline/60 md:hidden">
            <li v-for="server in servers" :key="`m-${server.id}`" class="flex flex-col gap-1 px-3 py-2">
              <div class="flex items-center gap-2">
                <Link v-if="server.url" :href="server.url" class="min-w-0 flex-1 truncate text-[14px] text-fg-1">
                  {{ server.hostname }}
                </Link>
                <span v-else class="min-w-0 flex-1 truncate text-[14px] text-fg-1">{{ server.hostname }}</span>
                <StatusBadge :status="server.status" />
                <StatusBadge v-if="server.health" :status="server.health" />
              </div>

              <div class="flex items-center gap-2 text-[11px] text-fg-3">
                <span class="uppercase tracking-wide">{{ server.type }}</span>
                <span v-if="server.load !== null" class="tabular-nums" :class="resolve(toneText, loadTone(server.load))">
                  {{ server.load }}%
                </span>
                <span class="tabular-nums">
                  {{ number(server.viewers) }}<template v-if="server.maxClients">/{{ number(server.maxClients) }}</template>
                </span>
                <span class="ml-auto" :class="server.heartbeatStale ? 'text-state-warn' : 'text-fg-3'">
                  {{ server.heartbeat ?? 'never' }}
                </span>
              </div>
            </li>
            <li v-if="!servers.length" class="px-3 py-2 text-[13px] text-fg-3">No servers are provisioned.</li>
          </ul>

          <div class="hidden overflow-x-auto md:block">
            <table class="w-full">
              <thead>
                <tr class="border-b border-hairline">
                  <th :class="head">Host</th>
                  <th :class="head">Type</th>
                  <th :class="head">Status</th>
                  <th :class="head">Health</th>
                  <th :class="[head, 'text-right']">Load</th>
                  <th :class="head">Heartbeat</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="server in servers" :key="server.id" class="border-b border-hairline/60 last:border-b-0">
                  <td :class="cell">
                    <Link v-if="server.url" :href="server.url" class="text-fg-1 hover:text-state-live">
                      {{ server.hostname }}
                    </Link>
                    <span v-else class="text-fg-1">{{ server.hostname }}</span>
                  </td>
                  <td :class="[cell, 'uppercase text-[11px] tracking-wide text-fg-2']">{{ server.type }}</td>
                  <td :class="cell"><StatusBadge :status="server.status" /></td>
                  <td :class="cell">
                    <StatusBadge v-if="server.health" :status="server.health" />
                    <span v-else class="text-fg-3">—</span>
                  </td>
                  <td :class="[cell, 'text-right tabular-nums']">
                    <span v-if="server.load !== null" :class="resolve(toneText, loadTone(server.load))">
                      {{ server.load }}%
                    </span>
                    <span v-else class="text-fg-3">—</span>
                    <span class="ml-1 text-[11px] text-fg-3">
                      {{ number(server.viewers) }}<template v-if="server.maxClients">/{{ number(server.maxClients) }}</template>
                    </span>
                  </td>
                  <td :class="[cell, server.heartbeatStale ? 'text-state-warn' : 'text-fg-2']">
                    {{ server.heartbeat ?? 'never' }}
                  </td>
                </tr>
                <tr v-if="!servers.length">
                  <td :class="[cell, 'text-fg-3']" colspan="6">No servers are provisioned.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section aria-label="Schedule" class="rounded border border-hairline bg-surface-2">
          <header class="flex h-9 items-center gap-2 border-b border-hairline px-3">
            <ManageIcon name="clock" :size="14" class="text-fg-2" />
            <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">
              On air and next {{ scheduleHours }} hours
            </h2>
          </header>

          <ul v-if="schedule.length" class="divide-y divide-hairline/60">
            <li v-for="show in schedule" :key="show.id" class="flex items-center gap-3 px-3 py-2">
              <span class="w-20 shrink-0 text-[13px] tabular-nums text-fg-2">
                {{ show.start ?? '—' }}<template v-if="show.end">–{{ show.end }}</template>
              </span>

              <StatusBadge :status="show.status" />

              <div class="min-w-0 flex-1">
                <p class="truncate text-[13px] text-fg-1">
                  <Link v-if="show.url" :href="show.url" class="hover:text-state-live">{{ show.title }}</Link>
                  <span v-else>{{ show.title }}</span>
                </p>
                <p class="flex items-center gap-1.5 text-[11px] text-fg-3">
                  <span v-if="show.source">{{ show.source }}</span>
                  <span
                    v-if="show.sourceStatus"
                    class="size-1.5 rounded-full"
                    :class="resolve(toneDot, show.sourceStatus.tone)"
                    :title="show.sourceStatus.label"
                  />
                  <span v-if="show.autoMode">auto</span>
                  <span v-if="show.startsIn">in {{ show.startsIn }}</span>
                </p>
              </div>

              <span
                v-if="show.status.label === 'Live'"
                class="shrink-0 text-[13px] tabular-nums"
                :class="resolve(toneText, 'live')"
              >{{ number(show.viewers) }}</span>
            </li>
          </ul>
          <p v-else class="px-3 py-2 text-[13px] text-fg-3">
            Nothing is on air and nothing starts in the next {{ scheduleHours }} hours.
          </p>
        </section>
      </div>

      <section
        v-if="viewers.perSource.length"
        aria-label="Viewers per source"
        class="rounded border border-hairline bg-surface-2"
      >
        <header class="flex h-9 items-center gap-2 border-b border-hairline px-3">
          <ManageIcon name="eye" :size="14" class="text-fg-2" />
          <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">Viewers per source</h2>
        </header>

        <ul class="divide-y divide-hairline/60">
          <li
            v-for="source in viewers.perSource"
            :key="source.name"
            class="flex items-center gap-3 px-3 py-1.5"
          >
            <StatusBadge :status="source.status" />
            <span class="min-w-0 flex-1 truncate text-[13px] text-fg-1">{{ source.name }}</span>
            <span class="text-[13px] tabular-nums text-fg-2">{{ number(source.viewers) }}</span>
          </li>
        </ul>
      </section>
    </div>
  </ManageLayout>
</template>
