<script setup>
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';
import SettingsNav from '@/Components/Manage/SettingsNav.vue';

defineProps({
  table: { type: Object, required: true },
  navigation: { type: Array, default: () => [] },
});
</script>

<template>
  <ManageLayout>
    <Head title="Categories settings" />

    <PageHeader
      title="Settings"
      subtitle="What a show is: a dance, a theatre piece, a musical performance. Recordings inherit their show's, and the archive filters on it."
      :actions="table.pageActions"
    />

    <!-- Same shell as the generated panes: this is a settings area whose contents
         happen to be rows rather than fields. -->
    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <SettingsNav :navigation="navigation" active="categories" />

      <div class="flex min-w-0 flex-1 flex-col">
        <FilterBar :table="table" />

        <DataTable :table="table" />

        <Pagination :meta="table.meta" />
      </div>
    </div>
  </ManageLayout>
</template>
