<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import SettingsNav from '@/Components/Manage/SettingsNav.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';

const props = defineProps({
  /** null when creating */
  event: { type: Object, default: null },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  navigation: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.event));

const form = useForm(
  props.event
    ? {
        name: props.event.name,
        slug: props.event.slug,
        starts_on: props.event.starts_on,
        ends_on: props.event.ends_on,
      }
    : {
        name: '',
        slug: '',
        starts_on: '',
        ends_on: '',
        ...props.defaults,
      },
);

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.events.update', props.event.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.events.store'));
};

const unfiled = computed(
  () => (props.event?.unfiled?.shows ?? 0) + (props.event?.unfiled?.recordings ?? 0),
);

const matching = ref(false);

// Files what happened in this window and is filed nowhere. Only unfiled rows move,
// so pressing it twice does nothing the second time.
const matchByDate = () => {
  matching.value = true;

  router.post(props.event.match_url, {}, {
    preserveScroll: true,
    onFinish: () => (matching.value = false),
  });
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? event.name : 'New event'" />

    <PageHeader
      :title="isEdit ? event.name : 'New event'"
      :subtitle="isEdit
        ? `${event.date_range} · ${event.shows_count} show(s), ${event.recordings_count} recording(s) filed directly`
        : 'A run of the convention. Its dates decide when the front page is a programme.'"
    >
      <template #actions>
        <StatusBadge v-if="isEdit" :status="event.state" />
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <SettingsNav :navigation="navigation" active="events" />

      <form class="flex min-w-0 flex-1 flex-col" @submit.prevent="submit">
        <div class="flex flex-col gap-4 p-4">
          <FormSection title="Event">
            <FormField
              v-model="form.name"
              label="Name"
              required
              :error="form.errors.name"
              helper="What the archive chip says. The name of this run, as people say it."
            />
            <FormField
              v-model="form.slug"
              label="Slug"
              mono
              :error="form.errors.slug"
              helper="Used in the archive URL. Left empty, it is made from the name."
            />
          </FormSection>

          <FormSection
            title="Dates"
            description="Inclusive of both days. While today falls inside them the site is in its live state: the front page is what is on and what is next. Outside them it is the archive."
          >
            <FormField
              v-model="form.starts_on"
              label="First day"
              type="date"
              narrow
              required
              :error="form.errors.starts_on"
            />
            <FormField
              v-model="form.ends_on"
              label="Last day"
              type="date"
              narrow
              required
              :error="form.errors.ends_on"
              helper="Runs to the end of this day, not its start."
            />
          </FormSection>

          <!-- The backfill. A show or a recording created before this event existed
               had nothing to inherit, and nobody is opening a hundred forms. -->
          <FormSection
            v-if="isEdit"
            title="Existing programme"
            description="Shows and recordings that happened inside this window and are filed under no run. Anything already filed is left alone."
          >
            <FormField label="Unfiled in this window">
              <div class="flex items-center gap-3">
                <span class="text-[13px] text-fg-1">
                  {{ event.unfiled.shows }} show(s), {{ event.unfiled.recordings }} recording(s)
                </span>
                <button
                  v-if="unfiled > 0"
                  type="button"
                  class="h-8 rounded border border-hairline px-2.5 text-[13px] text-fg-1 transition-colors hover:border-state-live/50 disabled:opacity-50"
                  :disabled="matching"
                  @click="matchByDate"
                >
                  {{ matching ? 'Filing...' : 'File them under this event' }}
                </button>
              </div>
            </FormField>
          </FormSection>
        </div>

        <FormActions
          :processing="form.processing"
          :dirty="form.isDirty"
          :submit-label="isEdit ? 'Save changes' : 'Create event'"
        />
      </form>
    </div>
  </ManageLayout>
</template>
