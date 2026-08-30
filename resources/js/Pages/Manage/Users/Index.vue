<script setup>
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';
import StatCard from '@/Components/Manage/StatCard.vue';

defineProps({
  table: { type: Object, required: true },
  // Null when the installation has notifications switched off.
  subscribers: { type: Object, default: null },
});
</script>

<template>
  <ManageLayout>
    <Head title="Users" />

    <PageHeader title="Users" :actions="table.pageActions" />

    <div v-if="subscribers" class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
      <StatCard label="Email subscribers" :value="subscribers.email" tone="info" />
      <StatCard label="Telegram subscribers" :value="subscribers.telegram" tone="info" />
      <StatCard label="Shows followed" :value="subscribers.shows" tone="idle" />
    </div>

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
