<script setup>
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';

defineProps({
  table: { type: Object, required: true },
});
</script>

<template>
  <ManageLayout>
    <Head title="Users" />

    <PageHeader title="Users" :actions="table.pageActions" />

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
