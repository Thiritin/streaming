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

    <Deferred data="storage">
      <template #fallback><span /></template>

      <StoragePanel v-if="storage" :storage="storage" />
    </Deferred>

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
