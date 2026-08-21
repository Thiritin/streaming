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
  notify_feedback: props.chat.notify_feedback,
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

    <form @submit.prevent="submit">
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
          v-model="form.notify_feedback"
          type="checkbox"
          label="Feedback"
          helper="Every report a viewer sends in, with the browser and the stream it happened on."
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
        description="An info-only chat gets the text and a link into this panel. With buttons, the message itself starts and ends the show."
      >
        <FormField
          v-model="form.interactive"
          type="checkbox"
          label="Allow actions from this chat"
          :error="form.errors.interactive"
          helper="Anyone who can read this chat can press them. Ending a show asks for a confirmation first; starting one does not."
        />
      </FormSection>

      <FormActions :processing="form.processing" :dirty="form.isDirty" submit-label="Save chat" />
    </form>
  </ManageLayout>
</template>
