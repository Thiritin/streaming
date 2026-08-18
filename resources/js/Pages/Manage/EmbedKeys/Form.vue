<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  defaults: { type: Object, default: () => ({}) },
});

const form = useForm({ name: '', ...props.defaults });

const submit = () => form.post(route('manage.embed-keys.store'));
</script>

<template>
  <ManageLayout>
    <Head title="New Display Key" />

    <PageHeader
      title="New Display Key"
      subtitle="Name it after the screen it goes on, so a revoked key is obvious later."
    />

    <form @submit.prevent="submit">
      <FormSection
        title="Key"
        description="A short code appears in the list once saved, short enough to type on a screen with no keyboard. Open it once; the screen stays signed in from then on."
      >
        <FormField
          v-model="form.name"
          label="Name"
          :error="form.errors.name"
          helper="Hall 2 foyer screen, Lobby TV, Bob's laptop"
          required
        />
      </FormSection>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        submit-label="Create key"
      />
    </form>
  </ManageLayout>
</template>
