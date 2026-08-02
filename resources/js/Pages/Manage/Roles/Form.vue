<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null when creating */
  role: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  members: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.role));

const form = useForm(
  props.role
    ? {
        name: props.role.name,
        slug: props.role.slug,
        description: props.role.description ?? '',
        chat_color: props.role.chat_color ?? '#808080',
        priority: props.role.priority,
        is_visible: props.role.is_visible,
        assigned_at_login: props.role.assigned_at_login,
        permissions: [...props.role.permissions],
      }
    : {
        name: '',
        slug: '',
        description: '',
        chat_color: '#808080',
        priority: 0,
        is_visible: true,
        assigned_at_login: true,
        permissions: [],
        ...props.defaults,
      },
);

const slugify = (value) =>
  value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

/*
 * The slug is what the identity provider mapping and every hasRole() check match
 * on, so it follows the name only while the role is new.
 */
watch(
  () => form.name,
  (name, previous) => {
    if (isEdit.value) {
      return;
    }

    if (form.slug === '' || form.slug === slugify(previous ?? '')) {
      form.slug = slugify(name);
    }
  },
);

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.roles.update', props.role.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.roles.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `Role ${role.name}` : 'New role'" />

    <PageHeader
      :title="isEdit ? role.name : 'New role'"
      :subtitle="isEdit ? `${role.users_count} member(s)` : 'A badge colour and a set of permissions'"
    >
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-col gap-4 p-4">
        <FormSection title="Role information">
          <FormField v-model="form.name" label="Name" required :error="form.errors.name" />
          <FormField
            v-model="form.slug"
            label="Slug"
            required
            mono
            :error="form.errors.slug"
            helper="What the identity provider mapping and permission checks match on."
          />
          <FormField
            v-model="form.description"
            label="Description"
            type="textarea"
            :error="form.errors.description"
            class="md:col-span-full"
          />
        </FormSection>

        <FormSection title="Chat appearance" :columns="3">
          <FormField label="Chat colour" :error="form.errors.chat_color">
            <div class="flex items-center gap-2">
              <input
                v-model="form.chat_color"
                type="color"
                class="size-8 shrink-0 cursor-pointer rounded border border-hairline bg-surface-2"
                aria-label="Chat colour swatch"
              />
              <input
                v-model="form.chat_color"
                type="text"
                placeholder="#808080"
                class="h-8 w-32 rounded border border-hairline bg-surface-2 px-2 font-mono text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
                aria-label="Chat colour"
              />
            </div>
          </FormField>
          <FormField
            v-model="form.priority"
            label="Priority"
            type="number"
            :min="0"
            :max="999"
            required
            :error="form.errors.priority"
            helper="Highest priority role wins when someone holds several."
          />
          <FormField
            v-model="form.is_visible"
            label="Show badge in chat"
            type="checkbox"
            :error="form.errors.is_visible"
          />
        </FormSection>

        <FormSection title="Permissions" :columns="1">
          <FormField
            v-model="form.assigned_at_login"
            label="Sync from the identity provider at login"
            type="checkbox"
            :error="form.errors.assigned_at_login"
            helper="On, the role is rewritten from the provider at every sign-in. Off, it sticks until someone changes it here."
          />
          <FormField label="Granted permissions" :error="form.errors.permissions">
            <CheckboxList
              v-model="form.permissions"
              :options="options.permissions"
              :columns="1"
            />
          </FormField>
        </FormSection>

        <FormSection v-if="isEdit && members.length" title="Members" :columns="1">
          <ul class="flex flex-wrap gap-2">
            <li v-for="member in members" :key="member.id">
              <Link
                :href="member.url"
                class="rounded border border-hairline px-2 py-1 text-[13px] text-fg-1 hover:text-state-live"
              >{{ member.name }}</Link>
            </li>
          </ul>
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="isEdit ? 'Save changes' : 'Create role'"
      />
    </form>
  </ManageLayout>
</template>
