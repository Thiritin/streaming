<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FileUploadField from '@/Components/Manage/FileUploadField.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null when creating */
  emote: { type: Object, default: null },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.emote));

const form = useForm(
  props.emote
    ? {
        name: props.emote.name,
        s3_key: props.emote.s3_key ?? '',
        is_global: props.emote.is_global,
        is_approved: props.emote.is_approved,
      }
    : {
        name: '',
        s3_key: '',
        is_global: true,
        is_approved: true,
        ...props.defaults,
      },
);

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.emotes.update', props.emote.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.emotes.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `Emote :${emote.name}:` : 'New emote'" />

    <PageHeader
      :title="isEdit ? `:${emote.name}:` : 'New emote'"
      :subtitle="isEdit ? `Used ${emote.usage_count} time(s)` : 'A 64×64 image chat can inline'"
    >
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-1 flex-col gap-4 p-4">
        <FormSection title="Emote">
          <FormField
            v-model="form.name"
            label="Name"
            required
            mono
            :error="form.errors.name"
            helper="Lowercase letters, numbers and underscores. Typed in chat as :name:."
          />
          <FormField label="Image" required :error="form.errors.s3_key">
            <FileUploadField
              v-model="form.s3_key"
              purpose="emote"
              :preview-url="emote?.preview_url ?? null"
              accept="image/*"
            />
          </FormField>
          <FormField
            v-model="form.is_global"
            label="Available to everyone"
            type="checkbox"
            :error="form.errors.is_global"
            helper="Off, only the uploader can use it."
          />
          <FormField
            v-model="form.is_approved"
            label="Approved"
            type="checkbox"
            :error="form.errors.is_approved"
            helper="Unapproved emotes are hidden from chat."
          />
        </FormSection>

        <FormSection v-if="isEdit" title="History">
          <FormField :model-value="emote.uploaded_by" label="Uploaded by" disabled />
          <FormField :model-value="emote.approved_by" label="Approved by" disabled />
          <FormField :model-value="emote.approved_at" label="Approved at" disabled />
          <FormField :model-value="String(emote.usage_count)" label="Usage count" disabled />
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="isEdit ? 'Save changes' : 'Create emote'"
      />
    </form>
  </ManageLayout>
</template>
