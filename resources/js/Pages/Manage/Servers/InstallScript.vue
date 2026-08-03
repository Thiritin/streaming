<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CodeBlock from '@/Components/Manage/CodeBlock.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  server: { type: Object, required: true },
  tabs: { type: Array, required: true },
  downloadUrl: { type: String, required: true },
  regenerateUrl: { type: String, required: true },
});

const active = ref(props.tabs[0]?.key ?? null);

const current = () => props.tabs.find((tab) => tab.key === active.value) ?? props.tabs[0];

const regenerate = () => router.post(props.regenerateUrl, {}, { preserveScroll: true });
</script>

<template>
  <ManageLayout>
    <Head :title="`Install script · ${server.hostname}`" />

    <PageHeader
      :title="`Install Script - Server #${server.id} (${server.type})`"
      :subtitle="server.hostname"
    >
      <template #actions>
        <Link
          :href="route('manage.servers.edit', server.id)"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="arrow-left" />
          Back to server
        </Link>
        <button
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
          @click="regenerate"
        >
          <ManageIcon name="refresh-cw" />
          Regenerate
        </button>
      </template>
    </PageHeader>

    <div class="flex flex-col gap-3 p-4">
      <nav class="flex flex-wrap gap-1" aria-label="Configuration files">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          class="h-7 rounded border px-2.5 text-[12px] transition-colors"
          :class="tab.key === active
            ? 'border-state-live/40 bg-state-live/10 text-state-live'
            : 'border-hairline text-fg-2 hover:bg-surface-3'"
          @click="active = tab.key"
        >
          {{ tab.label }}
        </button>
      </nav>

      <CodeBlock
        v-if="current()"
        :key="current().key"
        :content="current().content"
        :filename="current().filename"
        :download-url="current().key === 'install' ? downloadUrl : null"
      />

      <p v-else class="rounded border border-hairline bg-surface-2 px-3 py-6 text-center text-[13px] text-fg-3">
        No provisioning templates apply to this server.
      </p>
    </div>
  </ManageLayout>
</template>
