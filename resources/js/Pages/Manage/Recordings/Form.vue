<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FileUploadField from '@/Components/Manage/FileUploadField.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null when creating */
  recording: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.recording));

const form = useForm(
  props.recording
    ? {
        show_id: props.recording.show_id ?? '',
        title: props.recording.title,
        slug: props.recording.slug,
        description: props.recording.description ?? '',
        date: props.recording.date,
        duration: props.recording.duration ?? '',
        m3u8_url: props.recording.m3u8_url,
        thumbnail_path: props.recording.thumbnail_path ?? '',
        is_published: props.recording.is_published,
        required_roles: [...props.recording.required_roles],
      }
    : {
        show_id: '',
        title: '',
        slug: '',
        description: '',
        date: '',
        duration: '',
        m3u8_url: '',
        thumbnail_path: '',
        is_published: true,
        required_roles: [],
        ...props.defaults,
      },
);

const slugify = (value) =>
  value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

// The slug is the public URL, so it stops following the title once the recording exists.
watch(
  () => form.title,
  (title, previous) => {
    if (isEdit.value) {
      return;
    }

    if (form.slug === '' || form.slug === slugify(previous ?? '')) {
      form.slug = slugify(title);
    }
  },
);

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.recordings.update', props.recording.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.recordings.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `Recording ${recording.title}` : 'New recording'" />

    <PageHeader
      :title="isEdit ? recording.title : 'New recording'"
      :subtitle="isEdit ? `${recording.views} view(s)` : 'An HLS playlist viewers can watch on demand'"
    >
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-col gap-4 p-4">
        <FormSection title="Recording">
          <FormField
            v-model="form.show_id"
            label="Associated show"
            type="select"
            :options="options.shows"
            :error="form.errors.show_id"
            helper="Optional. Links the recording back to what was broadcast."
          />
          <FormField v-model="form.title" label="Title" required :error="form.errors.title" />
          <FormField
            v-model="form.slug"
            label="Slug"
            required
            mono
            :error="form.errors.slug"
            helper="The public URL for this recording."
          />
          <FormField
            v-model="form.date"
            label="Date"
            type="datetime-local"
            required
            :error="form.errors.date"
          />
          <FormField
            v-model="form.description"
            label="Description"
            type="textarea"
            :error="form.errors.description"
            class="md:col-span-full"
          />
        </FormSection>

        <FormSection title="Media" :columns="1">
          <FormField
            v-model="form.m3u8_url"
            label="M3U8 URL"
            required
            mono
            :error="form.errors.m3u8_url"
            helper="The HLS playlist the player loads."
          />
          <FormField
            v-model="form.duration"
            label="Duration"
            type="number"
            :min="0"
            :error="form.errors.duration"
            helper="Seconds. Left empty, it is read off the playlist in the background."
          />
          <FormField
            label="Thumbnail"
            :error="form.errors.thumbnail_path"
            :helper="recording?.thumbnail_error
              ? `Last automatic capture failed: ${recording.thumbnail_error}`
              : 'Left empty, a frame is captured from the video.'"
          >
            <FileUploadField
              v-model="form.thumbnail_path"
              purpose="recording_thumbnail"
              :preview-url="recording?.thumbnail_url ?? null"
              accept="image/*"
            />
          </FormField>
        </FormSection>

        <FormSection title="Access" :columns="1">
          <FormField
            v-model="form.is_published"
            label="Published"
            type="checkbox"
            :error="form.errors.is_published"
            helper="Off, the recording is invisible to viewers."
          />
          <FormField
            label="Required roles"
            :error="form.errors.required_roles"
            helper="Leave empty for public access. Otherwise a viewer needs at least one of these."
          >
            <CheckboxList
              v-model="form.required_roles"
              :options="options.roles"
              empty-label="No roles exist yet."
            />
          </FormField>
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="isEdit ? 'Save changes' : 'Create recording'"
      />
    </form>
  </ManageLayout>
</template>
