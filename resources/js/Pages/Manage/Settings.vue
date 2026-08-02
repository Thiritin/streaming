<script setup>
/**
 * System settings, rendered from the registry in config/settings.php: a group becomes a
 * section and a field becomes a control, so adding a knob server-side needs no change
 * here.
 *
 * Every field shows whether it is overriding the shipped default and can be put back
 * individually, which is safer than the all-or-nothing reset at the bottom.
 */
import { computed, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FileUploadField from '@/Components/Manage/FileUploadField.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  groups: { type: Array, required: true },
});

const fields = computed(() => props.groups.flatMap((group) => group.fields));

const form = useForm({
  values: Object.fromEntries(fields.value.map((field) => [field.key, field.value ?? ''])),
});

/** Defaults keyed by field, so "use the default" needs no round trip. */
const defaults = reactive(
  Object.fromEntries(fields.value.map((field) => [field.key, field.default ?? ''])),
);

const isDefault = (field) => (form.values[field.key] ?? '') === (defaults[field.key] ?? '');

const useDefault = (field) => {
  form.values[field.key] = defaults[field.key] ?? '';
};

const submit = () => form.put(route('manage.settings.update'), { preserveScroll: true });

const resetAll = () => {
  if (!window.confirm('Delete every saved value and go back to the shipped defaults? Uploaded files are kept.')) {
    return;
  }

  router.post(route('manage.settings.reset'), {}, { preserveScroll: true });
};

const accept = (type) => (type === 'video' ? 'video/mp4,video/webm' : 'image/*');
</script>

<template>
  <ManageLayout>
    <Head title="Settings" />

    <PageHeader
      title="Branding & texts"
      subtitle="What makes this installation this convention's. Saved values override the shipped defaults."
    />

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-col gap-4 p-4">
        <FormSection
          v-for="group in groups"
          :key="group.key"
          :title="group.label"
          :description="group.description"
          :columns="group.columns ?? 2"
        >
          <template v-for="field in group.fields" :key="field.key">
            <!-- Uploads and colours need their own control; everything else is a plain field. -->
            <FormField
              v-if="field.type === 'image' || field.type === 'video'"
              :label="field.label"
              :helper="field.helper"
              :error="form.errors[`values.${field.key}`]"
            >
              <div class="flex flex-col gap-1.5">
                <FileUploadField
                  v-model="form.values[field.key]"
                  :purpose="field.purpose"
                  :preview-url="field.previewUrl"
                  :accept="accept(field.type)"
                />
                <button
                  v-if="!isDefault(field)"
                  type="button"
                  class="self-start text-[11px] text-fg-3 underline-offset-2 hover:text-fg-1 hover:underline"
                  @click="useDefault(field)"
                >
                  Use the default
                </button>
              </div>
            </FormField>

            <FormField
              v-else-if="field.type === 'color'"
              :label="field.label"
              :helper="field.helper"
              :error="form.errors[`values.${field.key}`]"
            >
              <div class="flex items-center gap-2">
                <input
                  v-model="form.values[field.key]"
                  type="color"
                  class="size-8 shrink-0 cursor-pointer rounded border border-hairline bg-surface-2"
                  :aria-label="`${field.label} swatch`"
                />
                <input
                  v-model="form.values[field.key]"
                  type="text"
                  placeholder="#000000"
                  class="h-8 w-32 rounded border border-hairline bg-surface-2 px-2 font-mono text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
                  :aria-label="field.label"
                />
                <button
                  v-if="!isDefault(field)"
                  type="button"
                  class="text-[11px] text-fg-3 underline-offset-2 hover:text-fg-1 hover:underline"
                  @click="useDefault(field)"
                >
                  Use the default
                </button>
              </div>
            </FormField>

            <FormField
              v-else
              v-model="form.values[field.key]"
              :label="field.label"
              :type="field.type === 'textarea' ? 'textarea' : 'text'"
              :required="field.required"
              :helper="field.helper"
              :placeholder="field.default ?? null"
              :error="form.errors[`values.${field.key}`]"
              :class="field.full ? 'md:col-span-full' : ''"
            />
          </template>
        </FormSection>

        <div class="flex items-center justify-between rounded border border-hairline bg-surface-2 px-3 py-2.5">
          <div>
            <p class="text-[13px] text-fg-1">Reset everything to the shipped defaults</p>
            <p class="text-[11px] text-fg-3">
              Deletes every saved value. Uploaded files stay on the disk.
            </p>
          </div>
          <button
            type="button"
            class="h-8 rounded border border-state-danger/35 px-3 text-[13px] text-state-danger transition-colors hover:bg-state-danger/12"
            @click="resetAll"
          >
            Reset to defaults
          </button>
        </div>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        submit-label="Save settings"
      />
    </form>
  </ManageLayout>
</template>
