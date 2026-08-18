<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';

defineProps({
  table: { type: Object, required: true },
});

/*
 * The rows are a snapshot of what screens reported seconds ago, and the whole point
 * of the page is watching a room switch over. Reloaded rather than polled by hand so
 * the table keeps its filters, sort and page.
 */
let refreshTimer = null;

const refresh = () => router.reload({ only: ['table'], preserveScroll: true, preserveState: true });

onMounted(() => { refreshTimer = setInterval(refresh, 10000); });
onBeforeUnmount(() => clearInterval(refreshTimer));
</script>

<template>
  <ManageLayout>
    <Head title="Screens" />

    <PageHeader
      title="Screens"
      subtitle="Every display currently signed in with a key, what it is playing, and where to send it."
      :actions="table.pageActions"
    />

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
