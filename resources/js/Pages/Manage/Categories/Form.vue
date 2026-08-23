<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import SettingsNav from '@/Components/Manage/SettingsNav.vue';

const props = defineProps({
  /** null when creating */
  category: { type: Object, default: null },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  navigation: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.category));

const form = useForm(
  props.category
    ? {
        name: props.category.name,
        slug: props.category.slug,
        sort_order: props.category.sort_order,
      }
    : {
        name: '',
        slug: '',
        sort_order: 0,
        ...props.defaults,
      },
);

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.categories.update', props.category.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.categories.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? category.name : 'New category'" />

    <PageHeader
      :title="isEdit ? category.name : 'New category'"
      :subtitle="isEdit
        ? `${category.shows_count} show(s), ${category.recordings_count} recording(s) labelled directly`
        : 'A label for the programme. It gates nothing.'"
    >
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <SettingsNav :navigation="navigation" active="categories" />

      <form class="flex min-w-0 flex-1 flex-col" @submit.prevent="submit">
        <div class="flex flex-col gap-4 p-4">
          <FormSection title="Category">
            <FormField
              v-model="form.name"
              label="Name"
              required
              :error="form.errors.name"
              helper="What viewers see on the archive chip. Dances, Theatre, Musical performances."
            />
            <FormField
              v-model="form.slug"
              label="Slug"
              mono
              :error="form.errors.slug"
              helper="Used in the archive URL. Left empty, it is made from the name."
            />
            <FormField
              v-model="form.sort_order"
              label="Order"
              type="number"
              :error="form.errors.sort_order"
              helper="Lower comes first in the chip bar. Ties fall back to alphabetical."
            />
          </FormSection>
        </div>

        <FormActions
          :processing="form.processing"
          :dirty="form.isDirty"
          :submit-label="isEdit ? 'Save changes' : 'Create category'"
        />
      </form>
    </div>
  </ManageLayout>
</template>
