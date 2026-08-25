<script setup>
/**
 * One comment, and everything needed to rule on it.
 *
 * The order is what a moderator actually reads: what was said, then what was said
 * about it, then where it sits in the thread. The decisions are in the header,
 * because by the time somebody has scrolled they have already made one.
 */
import { Head, Link } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

defineProps({
  comment: { type: Object, required: true },
  actions: { type: Array, default: () => [] },
});
</script>

<template>
  <ManageLayout>
    <Head :title="`Comment by ${comment.author}`" />

    <PageHeader :title="`Comment by ${comment.author}`" :subtitle="comment.posted_exact">
      <template #actions>
        <Link
          :href="comment.index_url"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="arrow-left" />
          All comments
        </Link>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-4 p-4">
      <div class="flex flex-wrap items-center gap-2">
        <StatusBadge :status="comment.state" />
        <span class="text-[12px] text-fg-3" :title="comment.posted_exact">{{ comment.posted }}</span>
        <span v-if="comment.edited" class="text-[12px] text-fg-3">· edited {{ comment.edited }}</span>
        <span v-if="comment.hearts" class="text-[12px] text-fg-3">· {{ comment.hearts }} hearts</span>
        <span v-if="comment.hidden_since" class="text-[12px] text-fg-3">· hidden {{ comment.hidden_since }}</span>
        <span v-if="comment.approved_by" class="text-[12px] text-fg-3">
          · approved by {{ comment.approved_by }} {{ comment.approved_at }}
        </span>
      </div>

      <section class="rounded border border-hairline bg-surface-2 p-4">
        <p class="whitespace-pre-wrap text-[14px] leading-relaxed text-fg-1">{{ comment.body }}</p>
      </section>

      <section v-if="comment.reports.length" class="flex flex-col gap-2">
        <h2 class="text-[13px] font-semibold text-fg-1">
          {{ comment.reports.length }} {{ comment.reports.length === 1 ? 'report' : 'reports' }}
        </h2>

        <ul class="flex flex-col divide-y divide-hairline rounded border border-hairline bg-surface-2">
          <li v-for="report in comment.reports" :key="report.id" class="flex flex-col gap-1 p-3">
            <p class="text-[12px] text-fg-3">
              {{ report.by }} · {{ report.at }}
              <span v-if="report.resolved">· ruled on</span>
            </p>
            <p class="text-[13px] text-fg-1">{{ report.message }}</p>
          </li>
        </ul>
      </section>

      <section class="flex flex-col gap-2">
        <h2 class="text-[13px] font-semibold text-fg-1">Where it sits</h2>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-1 rounded border border-hairline bg-surface-2 p-3 sm:grid-cols-2">
          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Recording</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">
              <a v-if="comment.recording_url" :href="comment.recording_url" target="_blank" rel="noopener" class="hover:underline">
                {{ comment.recording }}
              </a>
              <span v-else>{{ comment.recording ?? '-' }}</span>
            </dd>
          </div>
          <div class="flex gap-2">
            <dt class="w-32 shrink-0 text-[12px] text-fg-3">Author</dt>
            <dd class="min-w-0 break-words text-[12px] text-fg-1">{{ comment.author }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="comment.parent" class="flex flex-col gap-2">
        <h2 class="text-[13px] font-semibold text-fg-1">Answering</h2>

        <Link :href="comment.parent.url" class="rounded border border-hairline bg-surface-2 p-3 transition-colors hover:bg-surface-3">
          <p class="text-[12px] text-fg-3">{{ comment.parent.author }}</p>
          <p class="text-[13px] text-fg-1">{{ comment.parent.body }}</p>
        </Link>
      </section>

      <section v-if="comment.replies.length" class="flex flex-col gap-2">
        <h2 class="text-[13px] font-semibold text-fg-1">
          {{ comment.replies.length }} {{ comment.replies.length === 1 ? 'reply' : 'replies' }} under it
        </h2>

        <p class="text-[12px] text-fg-3">Deleting this comment deletes these with it.</p>

        <ul class="flex flex-col divide-y divide-hairline rounded border border-hairline bg-surface-2">
          <li v-for="reply in comment.replies" :key="reply.id">
            <Link :href="reply.url" class="flex flex-col gap-1 p-3 transition-colors hover:bg-surface-3">
              <span class="text-[12px] text-fg-3">{{ reply.author }}</span>
              <span class="text-[13px] text-fg-1">{{ reply.body }}</span>
            </Link>
          </li>
        </ul>
      </section>
    </div>
  </ManageLayout>
</template>
