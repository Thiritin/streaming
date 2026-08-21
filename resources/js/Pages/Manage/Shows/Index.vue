<script setup>
import { watch } from 'vue';
import { Head, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';
import { useInlineEdit } from '@/Components/Manage/useInlineEdit.js';

const props = defineProps({
  table: { type: Object, required: true },
});

// 5s, as in Filament: viewer counts move constantly and auto-mode flips shows live on its
// own, so an operator watching this page must not have to reload it.
const poll = usePoll(5000, { only: ['table'] });

const { isEnabled: inlineEditing } = useInlineEdit(() => props.table.name);

/*
 * The poll is off while inline editing is on. A reload lands a fresh row set under the
 * operator, which closes an open source dropdown and snaps a half-corrected time back to
 * what is stored - and every save reloads the table anyway, so nothing goes stale.
 */
watch(inlineEditing, (on) => (on ? poll.stop() : poll.start()));
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
