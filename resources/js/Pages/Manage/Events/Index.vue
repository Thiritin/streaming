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
  /** The run happening now, or null between runs. */
  current: { type: Object, default: null },
  /** The next run that has not started, or null when nothing further is scheduled. */
  next: { type: Object, default: null },
});
</script>

<template>
  <ManageLayout>
    <Head title="Events settings" />

    <PageHeader
      title="Settings"
      subtitle="The runs of the convention and the days they cover. While a run is on, the front page is a programme; between runs it is the archive."
      :actions="table.pageActions"
    />

    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <SettingsNav :navigation="navigation" active="events" />

      <div class="flex min-w-0 flex-1 flex-col">
        <!-- What the dates currently add up to, said plainly. The table below is
             the calendar; this is the one answer everybody actually wants from it. -->
        <div class="border-b border-hairline px-4 py-3 text-[13px]">
          <p v-if="current" class="text-fg-1">
            <span class="font-medium">{{ current.name }}</span> is on now, {{ current.dates }}.
            The front page is showing the programme.
          </p>
          <p v-else class="text-fg-2">
            No run is on. The front page is showing the archive.
            <span v-if="next" class="text-fg-1">
              {{ next.name }} opens it again on {{ next.dates }}.
            </span>
          </p>
        </div>

        <FilterBar :table="table" />

        <DataTable :table="table" />

        <Pagination :meta="table.meta" />
      </div>
    </div>
  </ManageLayout>
</template>
