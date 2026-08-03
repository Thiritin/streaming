<script setup>
import { Head, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';

defineProps({
  table: { type: Object, required: true },
});

// Filament polled this table on its default interval. Viewer counts, heartbeats and
// provisioning states all move on their own, so the rows refresh without the operator
// touching anything; only the data props reload.
usePoll(10000, { only: ['table'] });
</script>

<template>
  <ManageLayout>
    <Head title="Servers" />

    <PageHeader
      title="Servers"
      subtitle="Origin and edge servers, provisioned by hand"
      :actions="table.pageActions"
    />

    <FilterBar :filters="table.filters" :search="table.search" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
