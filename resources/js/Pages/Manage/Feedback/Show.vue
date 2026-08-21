<script setup>
/**
 * One report, read-only apart from its status.
 *
 * The message is the top of the page and the diagnostics sit under it, because the
 * order somebody reads this in is "what went wrong" and then "on what". The blob is
 * printed exactly as the browser sent it, grouped, with no interpretation: a value
 * that looks wrong is the point of collecting it.
 */
import { Head, Link } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

defineProps({
  report: { type: Object, required: true },
  actions: { type: Array, default: () => [] },
});
</script>

<template>
  <ManageLayout>
    <Head :title="`Report from ${report.reporter}`" />

    <PageHeader :title="`Report from ${report.reporter}`" :subtitle="report.received_exact">
      <template #actions>
        <Link
          :href="report.index_url"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="arrow-left" />
          All feedback
        </Link>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-4 p-4">
      <div class="flex flex-wrap items-center gap-2">
        <StatusBadge :status="report.type" />
        <StatusBadge :status="report.status" />
        <span class="text-[12px] text-fg-3" :title="report.received_exact">{{ report.received }}</span>
        <span v-if="report.handled_by" class="text-[12px] text-fg-3">
          · last touched by {{ report.handled_by }} {{ report.handled_at }}
        </span>
      </div>

      <section class="rounded border border-hairline bg-surface-2 p-4">
        <p class="whitespace-pre-wrap text-[14px] leading-relaxed text-fg-1">{{ report.message }}</p>
      </section>

      <section class="flex flex-col gap-2">
        <h2 class="text-[13px] font-semibold text-fg-1">Who and where</h2>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-1 rounded border border-hairline bg-surface-2 p-3 sm:grid-cols-2">
          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Account</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">{{ report.account ?? 'Guest' }}</dd>
          </div>

          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Telegram</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">
              <a
                v-if="report.telegram"
                :href="report.telegram_url"
                target="_blank"
                rel="noopener"
                class="text-state-live hover:underline"
              >{{ report.telegram }}</a>
              <span v-else class="text-fg-3">Not given</span>
            </dd>
          </div>

          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Watching</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">
              <Link v-if="report.show_url" :href="report.show_url" class="text-state-live hover:underline">
                {{ report.show }}
              </Link>
              <span v-else>{{ report.show ?? report.source ?? '-' }}</span>
            </dd>
          </div>

          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Source</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">{{ report.source ?? '-' }}</dd>
          </div>

          <div class="flex gap-2 sm:col-span-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Page</dt>
            <dd class="min-w-0 break-all text-[12px] text-fg-1">{{ report.url ?? '-' }}</dd>
          </div>

          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Address</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">{{ report.ip ?? '-' }}</dd>
          </div>

          <div class="flex gap-2 sm:col-span-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">User agent</dt>
            <dd class="min-w-0 break-all text-[12px] text-fg-1">{{ report.user_agent ?? '-' }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="report.diagnostics.length" class="flex flex-col gap-2">
        <h2 class="text-[13px] font-semibold text-fg-1">What their browser reported</h2>

        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
          <div
            v-for="group in report.diagnostics"
            :key="group.group"
            class="rounded border border-hairline bg-surface-2 p-3"
          >
            <p class="text-[11px] font-medium uppercase tracking-wide text-fg-2">{{ group.group }}</p>

            <dl class="mt-2 flex flex-col gap-1">
              <div v-for="row in group.rows" :key="`${group.group}-${row.label}`" class="flex gap-2">
                <dt class="w-36 shrink-0 text-[12px] text-fg-3">{{ row.label }}</dt>
                <dd class="min-w-0 break-all text-[12px] text-fg-1">{{ row.value }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </section>
    </div>
  </ManageLayout>
</template>
