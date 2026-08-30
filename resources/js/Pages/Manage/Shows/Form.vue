<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import ShowStatusControl from '@/Components/Manage/ShowStatusControl.vue';
import ScheduleRow from '@/Components/Manage/ScheduleRow.vue';

const props = defineProps({
  /** null when creating */
  show: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.show));
const isLive = computed(() => Boolean(props.show?.is_live));

const form = useForm({ ...(props.show ?? props.defaults) });

const slugify = (value) =>
  value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

// Create only, and dated: two shows can share a title across days, so the date keeps the
// slug unique without the operator thinking about it. An existing slug is a public URL and
// is never rewritten from here.
watch([() => form.title, () => form.scheduled_start], ([title, start]) => {
  if (isEdit.value || !title) {
    return;
  }

  const date = (start ?? '').slice(0, 10);
  form.slug = date ? `${slugify(title)}-${date}` : slugify(title);
});

// Status transitions belong beside the status; the header keeps navigation and delete.
const TRANSITIONS = ['go_live', 'end_stream', 'cancel', 'set_status'];

const statusActions = computed(() => props.actions.filter((action) => TRANSITIONS.includes(action.name)));
const headerActions = computed(() => props.actions.filter((action) => !TRANSITIONS.includes(action.name)));

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.shows.update', props.show.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.shows.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `Show ${show.title}` : 'New show'" />

    <PageHeader
      :title="isEdit ? show.title : 'New show'"
      :subtitle="isEdit
        ? `${show.viewer_count} watching · peak ${show.peak_viewer_count} · ${show.formatted_duration ?? 'not started'}`
        : 'Schedule a broadcast on one of the sources'"
    >
      <template #actions>
        <ActionButton
          v-for="action in headerActions"
          :key="action.name"
          :action="action"
        />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-1 flex-col gap-4 p-4">
        <FormSection title="Show information">
          <FormField v-model="form.title" label="Title" required :error="form.errors.title" />
          <FormField
            v-if="isLive"
            label="Slug"
            :model-value="form.slug"
            readonly
            mono
            helper="Locked while live: people are watching this URL right now."
          />
          <FormField
            v-else
            v-model="form.slug"
            label="Slug"
            required
            mono
            :error="form.errors.slug"
            :helper="isEdit ? 'This is the public URL. Changing it breaks existing links.' : 'Generated from the title and date'"
          />
          <FormField
            v-model="form.source_id"
            label="Source"
            type="select"
            :options="options.sources"
            required
            :error="form.errors.source_id"
            helper="Which channel this show streams on"
          />
          <FormField
            v-model="form.category_id"
            label="Category"
            type="select"
            :options="options.categories"
            :error="form.errors.category_id"
            helper="What kind of thing this is. Its recordings inherit it, and the archive filters on it."
          />
          <FormField
            v-model="form.event_id"
            label="Event"
            type="select"
            :options="options.events"
            :error="form.errors.event_id"
            helper="Which run of the convention this belongs to. Filled in from the scheduled date when a new show falls inside one."
          />
          <FormField
            v-model="form.description"
            label="Description"
            type="textarea"
            helper="Markdown: **bold**, _italics_, lists, links. Imported sessions keep the abstract as pretalx wrote it."
            :error="form.errors.description"
          />
        </FormSection>

        <FormSection
          title="Schedule"
          description="Drives the programme guide and the public grid. Edit the duration to push the end time out."
        >
          <FormField label="Scheduled" required :error="form.errors.scheduled_start || form.errors.scheduled_end">
            <ScheduleRow
              v-model:start="form.scheduled_start"
              v-model:end="form.scheduled_end"
            />
          </FormField>
        </FormSection>

        <FormSection
          title="Recording markers"
          description="Where the recording is cut. Written when the show goes live and ends; correct them to the second here."
        >
          <FormField
            v-model="form.actual_start"
            label="Actual start"
            type="datetime-local"
            step="1"
            :error="form.errors.actual_start"
            helper="In point of the recording"
          />
          <FormField
            v-model="form.actual_end"
            label="Actual end"
            type="datetime-local"
            step="1"
            :error="form.errors.actual_end"
            helper="Out point of the recording"
          />
        </FormSection>

        <FormSection v-if="isEdit" title="Status">
          <FormField label="Current status">
            <ShowStatusControl :status="show.status" :actions="statusActions" />
          </FormField>
        </FormSection>

        <FormSection
          title="Auto mode"
          description="Starts the show when its source comes online, and stops it at the hard stop even if the source keeps pushing. See docs/admin/auto-mode.md."
        >
          <FormField
            v-model="form.auto_mode"
            label="Auto mode"
            type="checkbox"
            :error="form.errors.auto_mode"
            helper="Off means this show only moves when you press a button"
          />
          <FormField
            v-if="form.auto_mode"
            v-model="form.auto_stop_at"
            label="Hard stop"
            type="datetime-local"
            :error="form.errors.auto_stop_at"
            helper="Last moment this may still be recording. Left empty it falls back to the scheduled end."
          />
        </FormSection>

        <FormSection title="Access">
          <FormField label="Visibility" required :error="form.errors.visibility">
            <div class="flex flex-col gap-1.5">
              <label class="flex items-center gap-2 text-[13px] text-fg-1">
                <input v-model="form.visibility" type="radio" value="public" class="accent-state-live" />
                Public — anyone signed in can watch
              </label>
              <label class="flex items-center gap-2 text-[13px] text-fg-1">
                <input v-model="form.visibility" type="radio" value="private" class="accent-state-live" />
                Private — only the roles below, nobody else sees the show at all
              </label>
            </div>
          </FormField>

          <FormField
            v-if="form.visibility === 'private'"
            label="Allowed roles"
            required
            :error="form.errors.required_roles"
          >
            <CheckboxList v-model="form.required_roles" :options="options.roles" />
          </FormField>
        </FormSection>

        <FormSection title="Recording">
          <!--
            The same field the recording plan edits, and the same one the schedule's
            "available later" badge reads. There used to be a separate announce flag
            beside it and nothing kept the two in step.
          -->
          <FormField
            v-model="form.publish_plan"
            label="Publish"
            type="select"
            :options="options.publish_plans"
            :error="form.errors.publish_plan"
          />
          <FormField v-if="isEdit" label="Live thumbnail">
            <div class="flex items-center gap-3">
              <div class="flex h-16 w-28 shrink-0 items-center justify-center overflow-hidden rounded border border-hairline bg-surface-2">
                <img v-if="show.thumbnail_url" :src="show.thumbnail_url" alt="" class="h-full w-full object-cover" />
                <span v-else class="text-[11px] text-fg-3">none yet</span>
              </div>
              <p class="text-[11px] text-fg-3">
                Captured off the stream while the show runs, so there is nothing to set here.
                A recording carries its own thumbnail, set on the recording.
              </p>
            </div>
          </FormField>
        </FormSection>

        <FormSection v-if="isEdit" title="Statistics" :columns="3">
          <FormField label="Current viewers" :model-value="show.viewer_count" readonly />
          <FormField label="Peak viewers" :model-value="show.peak_viewer_count" readonly />
          <FormField label="Duration" :model-value="show.formatted_duration ?? '—'" readonly />
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="isEdit ? 'Save changes' : 'Create show'"
      />
    </form>
  </ManageLayout>
</template>
