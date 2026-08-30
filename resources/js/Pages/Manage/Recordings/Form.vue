<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FileUploadField from '@/Components/Manage/FileUploadField.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import CutEditor from '@/Components/Manage/CutEditor.vue';
import SkipEditor from '@/Components/Recordings/SkipEditor.vue';
import VideoPlayer from '@/Components/Player/VideoPlayer.vue';

const props = defineProps({
  /** null when creating */
  recording: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  /** Bounds of the source archive, { from, to }. Absent for recordings that are not cuts. */
  available: { type: Object, default: () => ({ from: null, to: null }) },
});

/**
 * A cut reads its media out of the source archive by time range, so the playlist URL is
 * generated rather than entered and the media fields below are read-only for it. A
 * recording registered from outside still carries its own playlist and keeps them.
 */
const isCut = computed(() => Boolean(props.recording?.starts_at));

const isEdit = computed(() => Boolean(props.recording));

// Left empty, a recording is whatever its show is; say which that is rather than
// leaving the field looking unset.
const categoryHelper = computed(() =>
  props.recording?.inherited_category
    ? `Left empty, this follows its show: ${props.recording.inherited_category}.`
    : 'Left empty, this follows its show. Set it to override, or for a recording with no show.',
);

const eventHelper = computed(() =>
  props.recording?.inherited_event
    ? `Left empty, this follows its show: ${props.recording.inherited_event}.`
    : 'Left empty, this follows its show. Set it for an edit imported without one.',
);

const form = useForm(
  props.recording
    ? {
        show_id: props.recording.show_id ?? '',
        category_id: props.recording.category_id ?? '',
        event_id: props.recording.event_id ?? '',
        title: props.recording.title,
        slug: props.recording.slug,
        description: props.recording.description ?? '',
        date: props.recording.date,
        duration: props.recording.duration ?? '',
        m3u8_url: props.recording.m3u8_url,
        thumbnail_path: props.recording.thumbnail_path ?? '',
        is_published: props.recording.is_published,
        required_roles: [...props.recording.required_roles],
        starts_at: props.recording.starts_at ?? null,
        ends_at: props.recording.ends_at ?? null,
        skip_segments: props.recording.skip_segments ?? [],
        // Handed straight back on save. If somebody re-cut the recording while this
        // form was open, the server refuses rather than writing skips that were
        // marked against media that has since moved.
        cut_fingerprint: props.recording.cut_fingerprint ?? null,
      }
    : {
        show_id: '',
        category_id: '',
        event_id: '',
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

/**
 * The frame grabbed in the cut editor goes through the ordinary upload field, so it lands
 * on the same endpoint, the same storage rules and the same preview as a picked file. Like
 * a picked file it only sets the form value: nothing is attached to the recording until
 * the form is saved.
 */
const thumbnailField = ref(null);

const uploadingThumbnail = computed(() => Boolean(thumbnailField.value?.uploading));

const onCapture = (file) => thumbnailField.value?.uploadFile(file);

/*
 * The playhead the skip editor marks against.
 *
 * Marking an intermission is a transport job - park the playhead, press in, park it
 * again, press out - so the section carries a player of its own rather than asking
 * the operator to read times off somewhere else and type them in.
 */
const skipPlayer = ref(null);
const skipTime = ref(0);

const seekSkipPlayer = (seconds) => skipPlayer.value?.seek(seconds);

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

<style scoped>
@reference "../../../../css/app.css";

/* Capped so the section stays a workbench rather than a cinema: the timeline under
   it is the thing being worked, and it has to stay on screen with the picture. */
.skip-preview {
  @apply mx-auto w-full max-w-3xl overflow-hidden rounded-lg border border-hairline bg-black;
  aspect-ratio: 16 / 9;
}
</style>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-1 flex-col gap-4 p-4">
        <FormSection title="Recording">
          <FormField
            v-model="form.show_id"
            label="Associated show"
            type="select"
            :options="options.shows"
            :error="form.errors.show_id"
            helper="Optional. Links the recording back to what was broadcast."
          />
          <FormField
            v-model="form.category_id"
            label="Category"
            type="select"
            :options="options.categories"
            :error="form.errors.category_id"
            :helper="categoryHelper"
          />
          <FormField
            v-model="form.event_id"
            label="Event"
            type="select"
            :options="options.events"
            :error="form.errors.event_id"
            :helper="eventHelper"
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

        <!-- Deliberately not inside a labelled FormField: the editor needs the full width
             of the section, and a label column would push the picture off centre. -->
        <FormSection v-if="isCut" title="Cut" :columns="1">
          <div class="md:col-span-full space-y-2">
            <CutEditor
              v-model:starts-at="form.starts_at"
              v-model:ends-at="form.ends_at"
              :available="available"
              :recording-id="recording.id"
              :capturing="uploadingThumbnail"
              @capture="onCapture"
            />

            <p v-if="form.errors.starts_at || form.errors.ends_at" class="text-xs text-danger-500">
              {{ form.errors.starts_at || form.errors.ends_at }}
            </p>
            <p v-if="recording?.build_error" class="text-xs text-danger-500">
              Last build failed: {{ recording.build_error }}
            </p>
            <p v-else-if="recording?.segment_count" class="text-xs text-fg-3">
              {{ recording.segment_count }} segments, built {{ recording.playlist_built_at }}.
            </p>
          </div>
        </FormSection>

        <FormSection title="Media" :columns="1">
          <FormField
            v-model="form.m3u8_url"
            label="M3U8 URL"
            :required="!isCut"
            :disabled="isCut"
            mono
            :error="form.errors.m3u8_url"
            :helper="isCut ? 'Generated from the cut.' : 'The HLS playlist the player loads.'"
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
              : isCut
                ? 'Left empty, a frame is captured from the video. Capture thumbnail in the cut editor grabs the frame on screen instead.'
                : 'Left empty, a frame is captured from the video.'"
          >
            <FileUploadField
              ref="thumbnailField"
              v-model="form.thumbnail_path"
              purpose="recording_thumbnail"
              :preview-url="recording?.thumbnail_url ?? null"
              accept="image/*"
            />
          </FormField>
        </FormSection>

        <!-- Same editor the watch page carries, so a skip marked from either side is
             the same thing. Only on an existing recording: a timeline needs a length,
             and one is not known until the playlist has been read. -->
        <FormSection v-if="isEdit" title="Skip points" :columns="1">
          <div class="md:col-span-full space-y-3">
            <div v-if="form.m3u8_url" class="skip-preview">
              <VideoPlayer
                ref="skipPlayer"
                :src="form.m3u8_url"
                :title="form.title"
                :poster="recording?.thumbnail_url ?? ''"
                :is-live="false"
                :autoplay="false"
                storage-key="manage-skip-player"
                @time-update="skipTime = $event"
              />
            </div>

            <SkipEditor
              v-model="form.skip_segments"
              :duration="Number(form.duration) || 0"
              :current-time="form.m3u8_url ? skipTime : null"
              @seek="seekSkipPlayer"
            />

            <p class="text-xs text-fg-3">
              Viewers inside one of these are offered a button. Nothing is cut, and nobody
              is moved without pressing it.
            </p>
            <p v-if="form.errors.skip_segments" class="text-xs text-danger-500">
              {{ form.errors.skip_segments }}
            </p>
          </div>
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
              empty-label="Nothing ticked: anyone can watch this recording."
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
