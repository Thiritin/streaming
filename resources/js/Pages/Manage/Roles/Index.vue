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
    <Head title="Roles" />

    <PageHeader
      title="Roles"
      subtitle="Chat badge colour and the permissions this panel is gated on."
      :actions="table.pageActions"
    />

    <FilterBar :filters="table.filters" :search="table.search" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
