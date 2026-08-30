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
    <Head title="Sign-in providers" />

    <PageHeader
      title="Settings"
      subtitle="The ways in that are not a password. Each row's callback URL has to be registered at the provider."
      :actions="table.pageActions"
    />

    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <SettingsNav :navigation="navigation" active="identity" />

      <div class="flex min-w-0 flex-1 flex-col">
        <FilterBar :table="table" />

        <DataTable :table="table" />

        <Pagination :meta="table.meta" />
      </div>
    </div>
  </ManageLayout>
</template>
