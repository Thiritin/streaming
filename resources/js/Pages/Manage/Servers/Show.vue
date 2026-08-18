<script setup>
/**
 * One server, read-only. Editing is a separate page: almost every visit here is to
 * check what a box is doing, and a form is the wrong thing to land on for that.
 *
 * Everything on the page comes from the per-minute heartbeat sample, apart from the
 * viewer count, which the app derives from `source_users` and the box never sees.
 */
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import ChartArea from '@/Components/Manage/ChartArea.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatCard from '@/Components/Manage/StatCard.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  server: { type: Object, required: true },
  metrics: { type: Object, required: true },
  actions: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
});

// Samples land once a minute, so a slower poll than the dashboard's is enough to keep
// the page honest without asking for data that cannot have changed.
usePoll(30000, { only: ['server', 'metrics'] });

const setRange = (range) => {
  router.get(
    route('manage.servers.show', props.server.id),
    { range },
    { only: ['metrics'], preserveScroll: true, preserveState: true, replace: true },
  );
};

const rangeClass = (value) =>
  value === props.metrics.range
    ? 'border-state-live/40 bg-state-live/10 text-state-live'
    : 'border-hairline text-fg-2 hover:bg-surface-3';

const details = [
  ['IP address', 'ip'],
  ['Port', 'port'],
  ['Hetzner ID', 'hetzner_id'],
  ['Instance size', 'server_type'],
  ['Max clients', 'max_clients'],
  ['Created', 'created_at'],
];
</script>

<template>
  <ManageLayout>
    <Head :title="`Server ${server.hostname}`" />

    <PageHeader
      :title="server.hostname"
      :subtitle="`${server.type.label} · ${server.is_cloud ? 'Hetzner Cloud' : 'manually managed'}${server.server_type ? ` · ${server.server_type}` : ''}`"
    >
      <template #actions>
        <Link
          :href="server.index_url"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="arrow-left" />
          All servers
        </Link>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-4 p-4">
      <div class="flex flex-wrap items-center gap-2">
        <StatusBadge :status="server.status" />
        <StatusBadge v-if="server.health_status" :status="server.health_status" />
        <StatusBadge :status="server.heartbeat" />
        <span class="text-[12px] text-fg-3" :title="server.last_heartbeat_exact">
          Last heartbeat {{ server.last_heartbeat }}
        </span>
        <span v-if="server.health_check_message" class="text-[12px] text-fg-3">
          · {{ server.health_check_message }}
        </span>
      </div>

      <section class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-[13px] font-semibold text-fg-1">Right now</h2>
          <span v-if="metrics.sampled_at" class="text-[11px] text-fg-3" :title="metrics.sampled_at_exact">
            sampled {{ metrics.sampled_at }}
          </span>
        </div>

        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
          <StatCard
            v-for="card in metrics.cards"
            :key="card.key"
            :label="card.label"
            :value="card.value"
            :tone="card.tone"
            :hint="card.hint"
          />
        </div>
      </section>

      <section class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-[13px] font-semibold text-fg-1">History</h2>

          <nav class="flex gap-1" aria-label="Time range">
            <button
              v-for="option in metrics.ranges"
              :key="option.value"
              type="button"
              class="rounded border px-2 py-1 text-[12px] transition-colors"
              :class="rangeClass(option.value)"
              @click="setRange(option.value)"
            >
              {{ option.label }}
            </button>
          </nav>
        </div>

        <div v-if="metrics.charts.length" class="grid gap-3 xl:grid-cols-2">
          <div
            v-for="chart in metrics.charts"
            :key="chart.key"
            class="rounded border border-hairline bg-surface-2 p-3"
          >
            <p class="mb-2 text-[12px] font-medium text-fg-2">{{ chart.label }}</p>
            <ChartArea :points="chart.points" :unit="chart.unit" :tone="chart.tone" :height="140" />
          </div>
        </div>

        <p v-else class="rounded border border-hairline bg-surface-2 px-3 py-8 text-center text-[13px] text-fg-3">
          No samples in this window. Provisioned servers report CPU, memory, disk and
          network once a minute from <span class="font-mono">/opt/streaming/heartbeat.sh</span>;
          a manually managed server only reports what you install there yourself. Samples
          are kept for {{ metrics.retention_days }} days.
        </p>
      </section>

      <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded border border-hairline bg-surface-2 p-3">
          <h2 class="mb-2 text-[13px] font-semibold text-fg-1">Details</h2>
          <dl class="flex flex-col gap-1.5 text-[12px]">
            <div v-for="[label, key] in details" :key="key" class="flex justify-between gap-3">
              <dt class="text-fg-3">{{ label }}</dt>
              <dd class="truncate font-mono text-fg-1">{{ server[key] ?? '—' }}</dd>
            </div>
            <div v-if="server.last_health_check" class="flex justify-between gap-3">
              <dt class="text-fg-3">Last health check</dt>
              <dd class="text-fg-1">{{ server.last_health_check }}</dd>
            </div>
          </dl>
        </section>

        <section class="rounded border border-hairline bg-surface-2 p-3">
          <h2 class="mb-2 text-[13px] font-semibold text-fg-1">
            Assigned viewers
            <span class="font-normal text-fg-3">({{ users.length }})</span>
          </h2>

          <ul v-if="users.length" class="flex max-h-64 flex-col gap-1 overflow-y-auto text-[12px]">
            <li v-for="user in users" :key="user.id" class="flex justify-between gap-3">
              <span class="truncate text-fg-1">{{ user.name }}</span>
              <span class="shrink-0 font-mono text-fg-3">{{ user.reg_id ?? user.sub }}</span>
            </li>
          </ul>

          <p v-else class="py-4 text-[12px] text-fg-3">
            No signed-in viewers are pinned to this server. Guests are counted through
            their session rows instead, so the viewer figure above can be higher than
            this list.
          </p>
        </section>
      </div>
    </div>
  </ManageLayout>
</template>
