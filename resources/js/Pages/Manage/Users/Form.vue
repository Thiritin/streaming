<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null while creating an account this installation holds itself */
  user: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  can: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  messages: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.user));

/*
 * Roles are the only thing an operator owns on an existing record. `sub`, `name` and
 * `reg_id` belong to the identity provider and are rendered read-only below. An edge is
 * not an account setting: it is chosen per viewing session, on the session row.
 */
const form = useForm(
  props.user
    ? { roles: [...props.user.roles] }
    : {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        roles: [],
        ...props.defaults,
      },
);

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.users.update', props.user.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.users.store'));
};

/*
 * The password saves on its own rather than with the roles: it is the one field here
 * that hands somebody a way in, and it is not something to change by accident while
 * ticking a role. Clearing it is the header action.
 */
const passwordForm = useForm({
  password: '',
  password_confirmation: '',
});

const setPassword = () => passwordForm.put(route('manage.users.password.update', props.user.id), {
  preserveScroll: true,
  onSuccess: () => passwordForm.reset(),
});
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `User ${user.name}` : 'New account'" />

    <PageHeader
      :title="isEdit ? user.name : 'New account'"
      :subtitle="isEdit ? `First seen ${user.created_at}` : 'An account this installation holds itself'"
    >
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-1 flex-col gap-4 p-4">
        <FormSection
          v-if="!isEdit"
          title="Account"
        >
          <FormField v-model="form.name" label="Name" required :error="form.errors.name" />
          <FormField v-model="form.email" label="Email" type="email" required :error="form.errors.email" />
          <FormField
            v-model="form.password"
            label="Password"
            type="password"
            required
            :error="form.errors.password"
          />
          <FormField
            v-model="form.password_confirmation"
            label="Confirm password"
            type="password"
            required
            :error="form.errors.password_confirmation"
          />
        </FormSection>

        <FormSection
          v-if="isEdit"
          title="Identity"
          :description="user.sub ? 'Owned by the identity provider and refreshed at each sign-in.' : null"
        >
          <FormField v-if="user.sub" :model-value="user.sub" label="Subject" mono disabled />
          <FormField :model-value="user.name" label="Name" disabled />
          <FormField :model-value="user.email ?? '—'" label="Email" disabled />
          <FormField
            v-if="user.has_password"
            :model-value="user.email_verified ? 'Yes' : 'No'"
            label="Address confirmed"
            disabled
          />
          <FormField :model-value="user.reg_id ?? '—'" label="Registration ID" disabled />
          <FormField :model-value="user.updated_at" label="Last updated" disabled />
        </FormSection>

        <FormSection
          v-if="isEdit && can.password"
          title="Password"
          :description="user.has_password ? 'Set' : 'Not set'"
        >
          <div class="contents" @keydown.enter.prevent="setPassword">
            <FormField
              v-model="passwordForm.password"
              label="New password"
              type="password"
              :error="passwordForm.errors.password"
            />
            <FormField
              v-model="passwordForm.password_confirmation"
              label="Confirm password"
              type="password"
              :error="passwordForm.errors.password_confirmation"
            />
          </div>

          <div class="flex justify-end pt-1">
            <button
              type="button"
              class="h-8 rounded border border-hairline px-3 text-[13px] font-medium text-fg-1 transition-colors hover:border-state-live/50 disabled:opacity-50"
              :disabled="passwordForm.processing"
              @click="setPassword"
            >
              {{ user.has_password ? 'Replace password' : 'Set password' }}
            </button>
          </div>
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
              empty-label="No roles attached: this account has attendee access only."
            />
          </FormField>
        </FormSection>

        <FormSection v-if="isEdit && messages.length" title="Recent chat messages" :columns="1">
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
        :submit-label="isEdit ? 'Save changes' : 'Create account'"
      />
    </form>
  </ManageLayout>
</template>
