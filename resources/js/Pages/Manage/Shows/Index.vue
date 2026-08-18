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

// 5s, as in Filament: viewer counts move constantly and auto-mode flips shows live on its
// own, so an operator watching this page must not have to reload it.
usePoll(5000, { only: ['table'] });
</script>

<template>
  <ManageLayout>
    <Head title="Shows" />

    <PageHeader
      title="Shows"
      subtitle="Ended shows are hidden until you turn the filter off"
      :actions="table.pageActions"
    />

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
