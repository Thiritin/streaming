<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  user: { type: Object, required: true },
  options: { type: Object, required: true },
  actions: { type: Array, default: () => [] },
  messages: { type: Array, default: () => [] },
});

/*
 * Only the two things an operator owns. `sub`, `name` and `reg_id` belong to the
 * identity provider and are rendered read-only below.
 */
const form = useForm({
  server_id: props.user.server_id ?? '',
  roles: [...props.user.roles],
});

const submit = () => form.put(route('manage.users.update', props.user.id), { preserveScroll: true });
</script>

<template>
  <ManageLayout>
    <Head :title="`User ${user.name}`" />

    <PageHeader :title="user.name" :subtitle="`First seen ${user.created_at}`">
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-col gap-4 p-4">
        <FormSection
          title="Identity"
          description="Owned by the identity provider and refreshed at each sign-in."
        >
          <FormField :model-value="user.sub" label="Subject" mono disabled />
          <FormField :model-value="user.name" label="Name" disabled />
          <FormField :model-value="user.reg_id ?? '—'" label="Registration ID" disabled />
          <FormField :model-value="user.updated_at" label="Last updated" disabled />
        </FormSection>

        <FormSection title="Assignment" :columns="1">
          <FormField
            v-model="form.server_id"
            label="Edge server"
            type="select"
            :options="options.servers"
            :error="form.errors.server_id"
            helper="Only active edge servers can take a viewer. Left unassigned, one is picked on the next request."
          />
        </FormSection>

        <FormSection
          title="Roles"
          description="Roles synced at login are overwritten on the next sign-in; the rest stick."
          :columns="1"
        >
          <FormField label="Attached roles" :error="form.errors.roles">
            <CheckboxList
              v-model="form.roles"
              :options="options.roles"
              empty-label="No roles exist yet."
            />
          </FormField>
        </FormSection>

        <FormSection v-if="messages.length" title="Recent chat messages" :columns="1">
          <div class="overflow-x-auto rounded border border-hairline">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-hairline bg-surface-2 text-[11px] uppercase tracking-wide text-fg-2">
                  <th class="h-7 px-3 text-left">Message</th>
                  <th class="h-7 px-3 text-left">Sent</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="message in messages"
                  :key="message.id"
                  class="border-b border-hairline/60 last:border-b-0"
                >
                  <td class="h-8 px-3 text-fg-1">{{ message.message }}</td>
                  <td class="h-8 px-3 tabular-nums text-fg-2">{{ message.created_at }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        submit-label="Save changes"
      />
    </form>
  </ManageLayout>
</template>
