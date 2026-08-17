<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CopyableText from '@/Components/Manage/CopyableText.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  /** null when creating */
  source: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  shows: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.source));

/*
 * Editing posts neither `slug` nor `status`: the slug is fixed once the source exists,
 * and status changes go through the Update Status action so there is only one path.
 */
const form = useForm(
  props.source
    ? {
        name: props.source.name,
        priority: props.source.priority,
        description: props.source.description ?? '',
        is_featured: props.source.is_featured,
      }
    : {
        name: '',
        slug: '',
        priority: 0,
        description: '',
        is_featured: false,
        ...props.defaults,
      },
);

/** Rotating the key belongs next to the key, not in the page header. */
const streamKeyAction = computed(() =>
  props.actions.find((action) => action.name === 'regenerate_key') ?? null,
);

const headerActions = computed(() =>
  props.actions.filter((action) => action.name !== 'regenerate_key'),
);

const slugify = (value) =>
  value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

// The slug is the RTMP stream name, so it follows the name until someone edits it by hand.
// Renaming an existing source deliberately does not move the slug: encoders are configured
// against it.
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
    form.put(route('manage.sources.update', props.source.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.sources.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `Source ${source.name}` : 'New source'" />

    <PageHeader
      :title="isEdit ? source.name : 'New source'"
      :subtitle="isEdit
        ? `${source.shows_count} shows · ${source.live_shows_count} live now`
        : 'A channel an encoder can push into'"
    >
      <template #actions>
        <ActionButton v-for="action in headerActions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-col gap-4 p-4">
        <FormSection title="Basic information">
          <FormField
            v-model="form.name"
            label="Name"
            required
            :error="form.errors.name"
            helper="Shown to viewers as the channel name"
          />
          <FormField
            v-if="isEdit"
            :model-value="source.slug"
            label="Stream name"
            mono
            disabled
            helper="Fixed after creation: it is the RTMP ingress path and the HLS route."
          />
          <FormField
            v-else
            v-model="form.slug"
            label="Stream name"
            required
            mono
            :error="form.errors.slug"
            helper="Becomes the RTMP ingress path and cannot be changed later."
          />
          <FormField
            v-model="form.priority"
            label="Priority"
            type="number"
            :min="0"
            :max="999"
            required
            :error="form.errors.priority"
            helper="Higher sorts first on the public grid"
          />
          <FormField
            v-model="form.is_featured"
            label="Featured channel"
            type="checkbox"
            :error="form.errors.is_featured"
            helper="Owns the hero on the landing page, and is where an ended show sends viewers. Only one source can be featured; turning this on turns it off elsewhere."
          />
          <FormField
            v-model="form.description"
            label="Description"
            type="textarea"
            :error="form.errors.description"
          />
        </FormSection>

        <FormSection
          v-if="isEdit"
          title="OBS configuration"
          description="Paste these two into OBS: Settings, then Stream."
          :columns="1"
        >
          <FormField label="Server URL">
            <CopyableText :value="source.rtmp_url" placeholder="No origin server is active" />
          </FormField>
          <FormField label="Stream key" helper="Rotate it here if it leaks.">
            <div class="flex flex-wrap items-center gap-2">
              <CopyableText :value="source.stream_key" masked />
              <ActionButton v-if="streamKeyAction" :action="streamKeyAction" />
            </div>
          </FormField>
        </FormSection>

        <FormSection
          v-if="isEdit"
          title="Control surface"
          description="Paste this into a Stream Control connection in Companion. Play starts the show in the current slot, or the next one if the slot has not begun; Stop ends whatever is live."
          :columns="1"
        >
          <FormField
            label="API base URL"
            helper="The control key is COMPANION_API_KEY in the environment, and is the same for every source."
          >
            <CopyableText :value="source.companion_url" />
          </FormField>
        </FormSection>

        <FormSection v-if="isEdit" title="Shows on this source" :columns="1">
          <div v-if="shows.length" class="overflow-x-auto rounded border border-hairline">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-hairline bg-surface-2 text-[11px] uppercase tracking-wide text-fg-2">
                  <th class="h-7 px-3 text-left">Title</th>
                  <th class="h-7 px-3 text-left">Status</th>
                  <th class="h-7 px-3 text-left">Scheduled</th>
                  <th class="h-7 px-3 text-right">Viewers</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="show in shows" :key="show.id" class="border-b border-hairline/60 last:border-b-0">
                  <td class="h-8 px-3">
                    <Link :href="show.url" class="text-fg-1 hover:text-state-live">{{ show.title }}</Link>
                  </td>
                  <td class="h-8 px-3"><StatusBadge :status="show.status" /></td>
                  <td class="h-8 px-3 tabular-nums text-fg-2">{{ show.scheduled_start ?? '—' }}</td>
                  <td class="h-8 px-3 text-right tabular-nums">{{ show.viewer_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-[13px] text-fg-3">No shows are scheduled on this source yet.</p>
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="isEdit ? 'Save changes' : 'Create source'"
      />
    </form>
  </ManageLayout>
</template>
