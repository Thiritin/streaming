<script setup>
/**
 * Global stream state. Each button broadcasts a StreamStatusEvent; "starting
 * soon" provisions the edge fleet and "offline" deletes it, so all four are
 * behind a confirmation.
 */
import { Head } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

defineProps({
  actions: { type: Array, default: () => [] },
});
</script>

<template>
  <ManageLayout>
    <Head title="Stream Control" />

    <PageHeader
      title="Stream Control"
      subtitle="Provision the fleet, go live, flag a problem, or tear everything down."
    />

    <div class="flex flex-col gap-4 p-4">
      <FormSection
        title="Transitions"
        description="The state is broadcast to every connected viewer. Capacity and server health are on the dashboard."
        :columns="1"
      >
        <div v-if="actions.length" class="flex flex-wrap gap-2">
          <ActionButton v-for="action in actions" :key="action.name" :action="action" />
        </div>
        <p v-else class="text-[13px] text-fg-3">
          You do not have permission to change the stream state.
        </p>
      </FormSection>
    </div>
  </ManageLayout>
</template>
