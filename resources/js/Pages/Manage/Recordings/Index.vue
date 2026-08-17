<script setup>
import { Deferred, Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';
import StoragePanel from '@/Components/Manage/StoragePanel.vue';

defineProps({
  table: { type: Object, required: true },
  storage: { type: Object, default: null },
});
</script>

<template>
  <ManageLayout>
    <Head title="Recordings" />

    <PageHeader
      title="Recordings"
      subtitle="On-demand playlists. Unpublished recordings are invisible to viewers."
      :actions="table.pageActions"
    />

    <div class="px-4 pt-4">
      <Deferred data="storage">
        <template #fallback>
          <div class="h-9 rounded border border-hairline bg-surface-2 px-3 text-[12px] leading-9 text-fg-3">
            Reading archive storage...
          </div>
        </template>

        <StoragePanel v-if="storage" :storage="storage" />
      </Deferred>
    </div>

    <FilterBar :filters="table.filters" :search="table.search" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
