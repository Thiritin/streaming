<script setup>
/**
 * System settings, rendered from the registry in config/settings.php: a group becomes a
 * section and a field becomes a control, so adding a knob server-side needs no change
 * here.
 *
 * Every field shows whether it is overriding the shipped default and can be put back
 * individually, which is safer than the all-or-nothing reset at the bottom.
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { clearAccentPreview, previewAccent } from '@/Components/Manage/colorRamp';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FileUploadField from '@/Components/Manage/FileUploadField.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  groups: { type: Array, required: true },
  /** Events the last connection test saw, so the slug can be picked rather than typed. */
  pretalxEvents: { type: Array, default: () => [] },
});

const fields = computed(() => props.groups.flatMap((group) => group.fields));

/*
 * Secrets are write-only: the page is told whether one is stored, never what it is, so a
 * blank field means "keep it". Removing one therefore needs a word of its own, which is
 * this sentinel; it matches Settings::CLEAR_SECRET.
 */
const CLEAR_SECRET = '__clear__';

/** A repeater arrives as rows and posts as rows; everything else is a string. */
const initial = (field) => (field.type === 'links'
  ? (field.value ?? []).map((row) => ({ label: row.label ?? '', url: row.url ?? '' }))
  : field.value ?? '');

const form = useForm({
  values: Object.fromEntries(fields.value.map((field) => [field.key, initial(field)])),
});

/** Defaults keyed by field, so "use the default" needs no round trip. */
const defaults = reactive(
  Object.fromEntries(fields.value.map((field) => [field.key, field.default ?? ''])),
);

/*
 * "Reset to defaults" deletes the saved rows and comes back through the same
 * component, so Inertia hands us fresh props while `useForm` is still holding
 * what the operator typed. Re-seeding from the new props is what makes the reset
 * visible without a reload.
 */
watch(
  () => props.groups,
  (groups) => {
    const next = groups.flatMap((group) => group.fields);

    next.forEach((field) => {
      defaults[field.key] = field.default ?? '';
    });

    form.defaults({ values: Object.fromEntries(next.map((field) => [field.key, initial(field)])) });
    form.reset();
  },
);

const isDefault = (field) => (field.type === 'links'
  ? JSON.stringify(form.values[field.key] ?? []) === JSON.stringify(defaults[field.key] ?? [])
  : (form.values[field.key] ?? '') === (defaults[field.key] ?? ''));

const useDefault = (field) => {
  form.values[field.key] = field.type === 'links'
    ? [...(defaults[field.key] ?? [])]
    : defaults[field.key] ?? '';
};

const addRow = (field) => {
  form.values[field.key] = [...(form.values[field.key] ?? []), { label: '', url: '' }];
};

const removeRow = (field, index) => {
  form.values[field.key] = form.values[field.key].filter((_, i) => i !== index);
};

/** Reordering is what the footer renders by, so it is worth having inline. */
const moveRow = (field, index, by) => {
  const rows = [...form.values[field.key]];
  const target = index + by;

  if (target < 0 || target >= rows.length) return;

  [rows[index], rows[target]] = [rows[target], rows[index]];
  form.values[field.key] = rows;
};

const submit = () => form.put(route('manage.settings.update'), { preserveScroll: true });

const resetAll = () => {
  if (!window.confirm('Delete every saved value and go back to the shipped defaults? Uploaded files are kept.')) {
    return;
  }

  router.post(route('manage.settings.reset'), {}, { preserveScroll: true });
};

const accept = (type) => (type === 'video' ? 'video/mp4,video/webm' : 'image/*');

/*
 * Pretalx: the connection can be proven before it is saved, and a successful test brings
 * back the events those credentials can see. The request is a partial reload of
 * `pretalxEvents` only, so the values being tested survive it - a full response would
 * re-seed the form from the saved settings and throw the typing away.
 */
const testing = ref(false);

const eventOptions = computed(() => {
  const options = props.pretalxEvents.map((event) => ({
    value: event.slug,
    label: event.date_from ? `${event.name} (${event.slug}, ${event.date_from})` : `${event.name} (${event.slug})`,
  }));

  const current = form.values.pretalx_event;

  // A slug saved before the list was fetched must stay selected rather than silently
  // switch to the first event in the dropdown.
  if (current && !options.some((option) => option.value === current)) {
    options.unshift({ value: current, label: `${current} (not in the list)` });
  }

  return [{ value: '', label: 'No event chosen' }, ...options];
});

const testPretalx = () => {
  testing.value = true;

  router.post(
    route('manage.settings.pretalx.test'),
    {
      url: form.values.pretalx_url,
      event: form.values.pretalx_event,
      token: form.values.pretalx_token,
    },
    {
      preserveScroll: true,
      preserveState: true,
      only: ['pretalxEvents', 'flash', 'errors'],
      onFinish: () => {
        testing.value = false;
      },
    },
  );
};

/**
 * Hex comparison is case-insensitive: the native picker writes lowercase while the
 * presets are stored as authored. The built-in swatch stores nothing, so it is the
 * selected one exactly when the field is empty.
 */
const isSelectedPreset = (field, preset) =>
  (form.values[field.key] ?? '').toLowerCase() === (preset.value ?? '').toLowerCase();

/** The swatch a preset row paints for "no accent saved". */
const builtInHex = (field) => field.presets?.find((preset) => !preset.value)?.hex ?? '#6a7282';

/*
 * A native colour input has no empty state: given "" it shows black, and the
 * first click on it writes #000000. Feeding it the built-in swatch instead keeps
 * "nothing saved" looking like what actually renders, and only a real pick
 * reaches the form.
 */
const swatchValue = (field) => ({
  get: () => form.values[field.key] || builtInHex(field),
  set: (value) => {
    form.values[field.key] = value;
  },
});

/*
 * Repaint the panel as the colour changes, so the ramp can be judged against real
 * UI rather than a 24px circle. The preview is undone on the way out: an edit
 * that is never saved should not follow the operator to the next screen.
 */
const accentField = computed(() => fields.value.find((field) => field.type === 'color') ?? null);

watch(
  () => (accentField.value ? form.values[accentField.value.key] : null),
  (hex) => previewAccent(hex),
);

onBeforeUnmount(clearAccentPreview);
</script>

<template>
  <ManageLayout>
    <Head title="Settings" />

    <PageHeader
      title="Settings"
      subtitle="What makes this installation this convention's, and what it connects to. Saved values override the shipped defaults."
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
              <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                  <input
                    :value="swatchValue(field).get()"
                    type="color"
                    class="size-8 shrink-0 cursor-pointer rounded border border-hairline bg-surface-2"
                    :aria-label="`${field.label} swatch`"
                    @input="swatchValue(field).set($event.target.value)"
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

                <!-- Known-good bases. The ramp is derived from whatever lands in
                     the field, so a preset is just a shortcut to a hex. -->
                <div v-if="field.presets" class="flex flex-wrap gap-1.5">
                  <button
                    v-for="preset in field.presets"
                    :key="preset.label"
                    type="button"
                    class="size-6 rounded-full border transition-transform hover:scale-110"
                    :class="isSelectedPreset(field, preset)
                      ? 'border-fg-1 ring-2 ring-fg-1/30'
                      : 'border-hairline'"
                    :style="{ backgroundColor: preset.hex }"
                    :title="preset.value ? `${preset.label} (${preset.hex})` : preset.label"
                    :aria-label="preset.label"
                    :aria-pressed="isSelectedPreset(field, preset)"
                    @click="form.values[field.key] = preset.value"
                  />
                </div>
              </div>
            </FormField>

            <FormField
              v-else-if="field.type === 'links'"
              :label="field.label"
              :helper="field.helper"
              :error="form.errors[`values.${field.key}`]"
              :class="field.full ? 'md:col-span-full' : ''"
            >
              <div class="flex flex-col gap-2">
                <div
                  v-for="(row, index) in form.values[field.key]"
                  :key="index"
                  class="flex items-center gap-2"
                >
                  <input
                    v-model="row.label"
                    type="text"
                    placeholder="Title"
                    class="h-8 w-40 shrink-0 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
                    :aria-label="`Link ${index + 1} title`"
                  />
                  <input
                    v-model="row.url"
                    type="url"
                    placeholder="https://example.org/privacy"
                    class="h-8 min-w-0 flex-1 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
                    :aria-label="`Link ${index + 1} address`"
                  />
                  <div class="flex shrink-0 items-center gap-1">
                    <button
                      type="button"
                      class="size-7 rounded border border-hairline text-[13px] text-fg-3 transition-colors hover:text-fg-1 disabled:opacity-30"
                      :disabled="index === 0"
                      title="Move up"
                      @click="moveRow(field, index, -1)"
                    >
                      &uarr;
                    </button>
                    <button
                      type="button"
                      class="size-7 rounded border border-hairline text-[13px] text-fg-3 transition-colors hover:text-fg-1 disabled:opacity-30"
                      :disabled="index === form.values[field.key].length - 1"
                      title="Move down"
                      @click="moveRow(field, index, 1)"
                    >
                      &darr;
                    </button>
                    <button
                      type="button"
                      class="size-7 rounded border border-state-danger/35 text-[13px] text-state-danger transition-colors hover:bg-state-danger/12"
                      title="Remove"
                      @click="removeRow(field, index)"
                    >
                      &times;
                    </button>
                  </div>
                </div>

                <p v-if="!form.values[field.key].length" class="text-[11px] text-fg-3">
                  No footer links. The footer link row is hidden entirely.
                </p>

                <!-- Row errors are keyed values.<field>.<index>.<column>, so they
                     are listed rather than shown against a single control. -->
                <p
                  v-for="(message, key) in form.errors"
                  :key="key"
                  v-show="key.startsWith(`values.${field.key}.`)"
                  class="text-[11px] text-state-danger"
                >
                  {{ message }}
                </p>

                <div class="flex items-center gap-3">
                  <button
                    type="button"
                    class="h-8 self-start rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
                    @click="addRow(field)"
                  >
                    Add link
                  </button>
                  <button
                    v-if="!isDefault(field)"
                    type="button"
                    class="text-[11px] text-fg-3 underline-offset-2 hover:text-fg-1 hover:underline"
                    @click="useDefault(field)"
                  >
                    Use the default
                  </button>
                </div>
              </div>
            </FormField>

            <FormField
              v-else-if="field.key === 'pretalx_event' && pretalxEvents.length"
              v-model="form.values[field.key]"
              :label="field.label"
              type="select"
              :options="eventOptions"
              :helper="field.helper"
              :error="form.errors[`values.${field.key}`]"
            />

            <FormField
              v-else-if="field.type === 'password'"
              :label="field.label"
              :helper="field.helper"
              :error="form.errors[`values.${field.key}`]"
              :class="field.full ? 'md:col-span-full' : ''"
            >
              <div class="flex items-center gap-2">
                <input
                  v-if="form.values[field.key] !== CLEAR_SECRET"
                  v-model="form.values[field.key]"
                  type="password"
                  autocomplete="new-password"
                  :placeholder="field.hasValue ? 'Saved' : 'Not set'"
                  class="h-8 min-w-0 flex-1 rounded border border-hairline bg-surface-2 px-2 font-mono text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
                  :aria-label="field.label"
                  @focus="$event.target.select()"
                />
                <p v-else class="flex h-8 min-w-0 flex-1 items-center text-[13px] text-state-danger">
                  Removed when you save.
                </p>

                <button
                  v-if="field.hasValue && form.values[field.key] !== CLEAR_SECRET"
                  type="button"
                  class="h-8 shrink-0 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
                  @click="form.values[field.key] = CLEAR_SECRET"
                >
                  Clear
                </button>
                <button
                  v-else-if="form.values[field.key] === CLEAR_SECRET"
                  type="button"
                  class="h-8 shrink-0 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
                  @click="form.values[field.key] = ''"
                >
                  Keep it
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

          <!-- Proving the connection is worth doing before saving it, and it is what
               fills the event dropdown. -->
          <div v-if="group.key === 'pretalx'" class="flex flex-wrap items-center gap-3 py-2">
            <button
              type="button"
              class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3 disabled:opacity-40"
              :disabled="testing || !form.values.pretalx_url"
              @click="testPretalx"
            >
              {{ testing ? 'Testing…' : 'Test connection' }}
            </button>
            <p class="text-[11px] text-fg-3">
              Uses the values above as they stand, saved or not, and loads the event list.
              A blank token falls back to the stored one.
            </p>
          </div>
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
