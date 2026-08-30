<script setup>
/**
 * What one chat is told, and what it is allowed to do about it.
 *
 * The buttons switch is the one that matters, so it says out loud what it means: every
 * member of the chat gets whatever it turns on, because Telegram has no idea which of
 * them works here.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CheckboxList from '@/Components/Manage/CheckboxList.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  chat: { type: Object, required: true },
  sources: { type: Array, default: () => [] },
});

const form = useForm({
  title: props.chat.title ?? '',
  enabled: props.chat.enabled,
  interactive: props.chat.interactive,
  notify_shows: props.chat.notify_shows,
  notify_recordings: props.chat.notify_recordings,
  notify_sources: props.chat.notify_sources,
  notify_feedback: props.chat.notify_feedback,
  notify_comments: props.chat.notify_comments,
  notify_health: props.chat.notify_health,
  source_ids: [...props.chat.source_ids],
});

const submit = () => form.put(route('manage.telegram.update', props.chat.id));
</script>

<template>
  <ManageLayout>
    <Head :title="chat.title ?? 'Telegram chat'" />

    <PageHeader
      :title="chat.title ?? `Chat ${chat.chat_id}`"
      :subtitle="chat.topic ? `${chat.type ?? 'chat'} · ${chat.chat_id} · ${chat.topic}` : `${chat.type ?? 'chat'} · ${chat.chat_id}`"
    >
      <template #actions>
        <Link
          :href="route('manage.telegram.index')"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="arrow-left" />
          All chats
        </Link>
      </template>
    </PageHeader>

    <p v-if="chat.last_error" class="mx-4 mt-4 rounded border border-hairline bg-surface-2 p-3 text-[12px] text-state-danger">
      Telegram last said: {{ chat.last_error }}
    </p>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-1 flex-col gap-4 p-4">
      <FormSection title="Chat" description="How this chat is named here, and whether the bot writes to it at all.">
        <FormField
          v-model="form.title"
          label="Name"
          :error="form.errors.title"
          helper="Whatever makes it obvious which room this is."
        />

        <FormField
          v-model="form.enabled"
          type="checkbox"
          label="Active"
          :error="form.errors.enabled"
          helper="Off keeps the chat and its history, and stops posting to it."
        />
      </FormSection>

      <FormSection title="What it gets" description="Nothing is on by default, including for a chat that linked itself with a code.">
        <FormField
          v-model="form.notify_shows"
          type="checkbox"
          label="Shows"
          helper="One message a few minutes before each slot, which then tracks the show through live and ended."
        />

        <FormField
          v-model="form.notify_recordings"
          type="checkbox"
          label="Recordings"
          helper="One message when a recording appears - cut, imported or created by hand - rewritten when it is published."
        />

        <FormField
          v-model="form.notify_sources"
          type="checkbox"
          label="Source alerts"
          helper="A line whenever a source goes online, offline or into error. A log, so nothing here is editable or pressable."
        />

        <FormField
          v-model="form.notify_feedback"
          type="checkbox"
          label="Feedback"
          helper="Every report a viewer sends in, with the browser and the stream it happened on."
        />

        <FormField
          v-model="form.notify_comments"
          type="checkbox"
          label="Reported comments"
          helper="A comment a report has taken down, with what was said about it. An interactive chat can approve it, delete it or ban its author from here."
        />

        <FormField
          v-model="form.notify_health"
          type="checkbox"
          label="Health alerts"
          helper="What the dashboard's alert list says: a server failing its health check or running out of disk, an edge nearly full, a live show whose source is not online. One message when a condition appears and one when it clears."
        />

        <FormField label="Sources" helper="Nothing ticked means every source. Tick some to make this a single room's chat.">
          <CheckboxList
            v-model="form.source_ids"
            :options="sources"
            :columns="2"
            empty-label="Everything on the installation."
          />
        </FormField>
      </FormSection>

      <FormSection
        title="Buttons"
        description="An info-only chat gets the text and a link into this panel. With buttons, the message itself starts and ends the show, publishes a recording and resolves a report."
      >
        <FormField
          v-model="form.interactive"
          type="checkbox"
          label="Allow actions from this chat"
          :error="form.errors.interactive"
          helper="Anyone who can read this chat can press them. Ending a show asks for a confirmation first; starting one, publishing a recording and resolving a report do not."
        />
      </FormSection>
      </div>

      <FormActions :processing="form.processing" :dirty="form.isDirty" submit-label="Save chat" />
    </form>
  </ManageLayout>
</template>
