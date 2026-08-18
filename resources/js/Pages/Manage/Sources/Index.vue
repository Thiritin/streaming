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

// Sources flip between online and offline on their own when an encoder connects or drops.
usePoll(10000, { only: ['table'] });
</script>

<template>
  <ManageLayout>
    <Head title="Sources" />

    <PageHeader
      title="Sources"
      subtitle="Channels an encoder pushes into. Higher priority sorts first on the public grid."
      :actions="table.pageActions"
    />

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
