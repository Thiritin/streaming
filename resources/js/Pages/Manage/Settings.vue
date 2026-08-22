<script setup>
/**
 * System settings, generated from the registry in config/settings.php. One group is one
 * pane, and only the pane on screen is posted.
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { clearAccentPreview, previewAccent } from '@/Components/Manage/colorRamp';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FileUploadField from '@/Components/Manage/FileUploadField.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  group: { type: Object, required: true },
  navigation: { type: Array, default: () => [] },
  pretalxEvents: { type: Array, default: () => [] },
});

const fields = computed(() => props.group.fields);

const paneUrl = (item) =>
  item.key === props.navigation[0]?.key
    ? route('manage.settings')
    : route('manage.settings.group', item.key);

const isActive = (item) => item.key === props.group.key;

// Matches Settings::CLEAR_SECRET: a blank secret keeps the stored one, this removes it.
const CLEAR_SECRET = '__clear__';

const initial = (field) => {
  if (field.type === 'links') {
    return (field.value ?? []).map((row) => ({
      label: row.label ?? '',
      url: row.url ?? '',
    }));
  }

  if (field.type === 'toggle') {
    return field.value === true;
  }

  return field.value ?? '';
};

const form = useForm({
  values: Object.fromEntries(fields.value.map((field) => [field.key, initial(field)])),
});

const defaults = reactive(
  Object.fromEntries(fields.value.map((field) => [field.key, field.default ?? ''])),
);

watch(
  () => props.group,
  (group) => {
    const next = group.fields;

    Object.keys(defaults).forEach((key) => delete defaults[key]);

    next.forEach((field) => {
      defaults[field.key] = field.default ?? '';
    });

    form.defaults({
      values: Object.fromEntries(next.map((field) => [field.key, initial(field)])),
    });
    form.reset();
  },
);

const isDefault = (field) =>
  field.type === 'links'
    ? JSON.stringify(form.values[field.key] ?? []) === JSON.stringify(defaults[field.key] ?? [])
    : (form.values[field.key] ?? '') === (defaults[field.key] ?? '');

const useDefault = (field) => {
  form.values[field.key] =
    field.type === 'links' ? [...(defaults[field.key] ?? [])] : (defaults[field.key] ?? '');
};

const addRow = (field) => {
  form.values[field.key] = [...(form.values[field.key] ?? []), { label: '', url: '' }];
};

const removeRow = (field, index) => {
  form.values[field.key] = form.values[field.key].filter((_, i) => i !== index);
};

const moveRow = (field, index, by) => {
  const rows = [...form.values[field.key]];
  const target = index + by;

  if (target < 0 || target >= rows.length) return;

  [rows[index], rows[target]] = [rows[target], rows[index]];
  form.values[field.key] = rows;
};

const submit = () =>
  form.put(route('manage.settings.update', props.group.key), {
    preserveScroll: true,
  });

const resetAll = () => {
  if (
    !window.confirm(
      'Delete every saved value and go back to the shipped defaults? Uploaded files are kept.',
    )
  ) {
    return;
  }

  router.post(route('manage.settings.reset'), {}, { preserveScroll: true });
};

const accept = (type) => (type === 'video' ? 'video/mp4,video/webm' : 'image/*');

// Partial reload of `pretalxEvents` only, so the values being tested survive the test.
const testing = ref(false);

const eventOptions = computed(() => {
  const options = props.pretalxEvents.map((event) => ({
    value: event.slug,
    label: event.date_from
      ? `${event.name} (${event.slug}, ${event.date_from})`
      : `${event.name} (${event.slug})`,
  }));

  const current = form.values.pretalx_event;

  // A slug saved before the list was fetched has to stay selected.
  if (current && !options.some((option) => option.value === current)) {
    options.unshift({
      value: current,
      label: `${current} (not in the list)`,
    });
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

const revealed = reactive({});
const copied = ref(null);

const generate = (field) => {
  const bytes = new Uint8Array(24);

  window.crypto.getRandomValues(bytes);

  form.values[field.key] = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  revealed[field.key] = true;
};

const copyValue = async (field) => {
  const value = form.values[field.key];

  if (!value) return;

  try {
    await navigator.clipboard.writeText(value);
    copied.value = field.key;
    window.setTimeout(() => (copied.value = null), 1500);
  } catch {}
};

// Case-insensitive: the native picker writes lowercase, presets are stored as authored.
const isSelectedPreset = (field, preset) =>
  (form.values[field.key] ?? '').toLowerCase() === (preset.value ?? '').toLowerCase();

const builtInHex = (field) => field.presets?.find((preset) => !preset.value)?.hex ?? '#6a7282';

// A native colour input has no empty state, so "nothing saved" shows the built-in hex.
const swatchValue = (field) => ({
  get: () => form.values[field.key] || builtInHex(field),
  set: (value) => {
    form.values[field.key] = value;
  },
});

// Repaint the panel as the colour changes; undone on the way out if never saved.
const accentField = computed(() => fields.value.find((field) => field.type === 'color') ?? null);

watch(
  () => (accentField.value ? form.values[accentField.value.key] : null),
  (hex) => previewAccent(hex),
);

onBeforeUnmount(clearAccentPreview);
</script>

<template>
  <ManageLayout>
    <Head :title="`${group.label} settings`" />

    <PageHeader title="Settings" :subtitle="group.description" />

    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <!-- Below lg the pane list is a strip of chips that scrolls sideways: a 256px column
           beside the form leaves nothing for the form. -->
      <nav
        class="shrink-0 border-b border-hairline lg:w-64 lg:border-r lg:border-b-0"
        aria-label="Settings sections"
      >
        <div class="sticky top-0 flex gap-1 overflow-x-auto p-2 [scrollbar-width:none] lg:block lg:space-y-1 lg:overflow-visible [&::-webkit-scrollbar]:hidden">
          <Link
            v-for="item in navigation"
            :key="item.key"
            :href="paneUrl(item)"
            class="relative flex shrink-0 items-center gap-2.5 overflow-hidden rounded border border-hairline px-3 py-2 transition-colors lg:items-start lg:border-0 lg:py-2.5"
            :class="isActive(item) ? 'bg-state-live/10' : 'hover:bg-surface-2'"
            :aria-current="isActive(item) ? 'page' : null"
          >
            <span
              v-if="isActive(item)"
              class="absolute top-1 bottom-1 left-0 hidden w-0.5 rounded-r bg-state-live lg:block"
              aria-hidden="true"
            />

            <ManageIcon
              :name="item.icon"
              :size="16"
              class="mt-px shrink-0"
              :class="isActive(item) ? 'text-state-live' : 'text-fg-3'"
            />

            <span class="min-w-0 flex-1">
              <span
                class="block whitespace-nowrap text-[13px] font-medium lg:truncate"
                :class="isActive(item) ? 'text-state-live' : 'text-fg-1'"
              >
                {{ item.label }}
              </span>
              <span class="mt-0.5 hidden text-[11px] leading-[15px] text-fg-3 lg:block">
                {{ item.blurb }}
              </span>
            </span>
          </Link>
        </div>
      </nav>

      <form
        v-if="group.action !== 'reset'"
        class="flex min-w-0 flex-1 flex-col"
        @submit.prevent="submit"
      >
        <div class="flex flex-col gap-4 p-4">
          <FormSection :title="group.label">
            <template v-for="field in group.fields" :key="field.key">
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

                  <div v-if="field.presets" class="flex flex-wrap gap-1.5">
                    <button
                      v-for="preset in field.presets"
                      :key="preset.label"
                      type="button"
                      class="size-6 rounded-full border transition-transform hover:scale-110"
                      :class="
                        isSelectedPreset(field, preset)
                          ? 'border-fg-1 ring-2 ring-fg-1/30'
                          : 'border-hairline'
                      "
                      :style="{
                        backgroundColor: preset.hex,
                      }"
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
                v-else-if="field.type === 'select'"
                v-model="form.values[field.key]"
                :label="field.label"
                type="select"
                :options="field.options ?? []"
                :helper="field.helper"
                :error="form.errors[`values.${field.key}`]"
                :class="field.full ? 'md:col-span-full' : ''"
              />

              <FormField
                v-else-if="field.type === 'toggle'"
                :label="field.label"
                :helper="field.helper"
                :error="form.errors[`values.${field.key}`]"
                :class="field.full ? 'md:col-span-full' : ''"
              >
                <input
                  v-model="form.values[field.key]"
                  type="checkbox"
                  class="size-4 accent-state-live"
                  :aria-label="field.label"
                />
              </FormField>

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
                  <p
                    v-else
                    class="flex h-8 min-w-0 flex-1 items-center text-[13px] text-state-danger"
                  >
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
                v-else-if="field.type === 'secret'"
                :label="field.label"
                :helper="field.helper"
                :error="form.errors[`values.${field.key}`]"
                :class="field.full ? 'md:col-span-full' : ''"
              >
                <div class="flex flex-col gap-1.5">
                  <div class="flex items-center gap-2">
                    <input
                      v-model="form.values[field.key]"
                      :type="revealed[field.key] ? 'text' : 'password'"
                      autocomplete="off"
                      spellcheck="false"
                      placeholder="Not set"
                      class="h-8 min-w-0 flex-1 rounded border border-hairline bg-surface-2 px-2 font-mono text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50"
                      :aria-label="field.label"
                      @focus="$event.target.select()"
                    />

                    <button
                      type="button"
                      class="grid size-8 shrink-0 place-items-center rounded border border-hairline text-fg-3 transition-colors hover:text-fg-1"
                      :title="revealed[field.key] ? 'Hide' : 'Reveal'"
                      @click="revealed[field.key] = !revealed[field.key]"
                    >
                      <ManageIcon :name="revealed[field.key] ? 'lock' : 'eye'" :size="13" />
                    </button>

                    <button
                      type="button"
                      class="grid size-8 shrink-0 place-items-center rounded border border-hairline text-fg-3 transition-colors hover:text-fg-1 disabled:opacity-40"
                      :disabled="!form.values[field.key]"
                      :title="copied === field.key ? 'Copied' : 'Copy'"
                      @click="copyValue(field)"
                    >
                      <ManageIcon :name="copied === field.key ? 'check' : 'copy'" :size="13" />
                    </button>

                    <button
                      type="button"
                      class="h-8 shrink-0 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
                      @click="generate(field)"
                    >
                      Generate
                    </button>
                  </div>

                  <p
                    v-if="!form.values[field.key] && field.emptyNote"
                    class="text-[11px] text-fg-3"
                  >
                    {{ field.emptyNote }}
                  </p>
                  <p
                    v-else-if="field.dirtyNote && !isDefault(field) && form.isDirty"
                    class="text-[11px] text-fg-3"
                  >
                    {{ field.dirtyNote }}
                  </p>
                </div>
              </FormField>

              <FormField
                v-else
                v-model="form.values[field.key]"
                :label="field.label"
                :type="field.type === 'textarea' ? 'textarea' : 'text'"
                :rows="field.rows ?? 3"
                :required="field.required"
                :helper="field.helper"
                :placeholder="field.default ?? null"
                :error="form.errors[`values.${field.key}`]"
                :class="field.full ? 'md:col-span-full' : ''"
              />
            </template>

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
                Uses the values above as they stand, saved or not, and loads the event list. A blank
                token falls back to the stored one.
              </p>
            </div>

            <!-- A pane can carry one line of copy with a link next to it; Control
                 surfaces uses it to hand over the built Companion module, and Imports
                 to hand over a build of the import tool per platform. -->
            <div v-if="group.note" class="flex flex-col gap-2 py-2">
              <div v-if="group.note.downloads?.length" class="flex flex-wrap items-center gap-2">
                <a
                  v-for="download in group.note.downloads"
                  :key="download.url"
                  :href="download.url"
                  class="inline-flex h-8 items-center gap-1.5 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
                >
                  <ManageIcon name="download" :size="14" />
                  {{ download.label }}
                </a>
              </div>

              <div class="flex flex-wrap items-center gap-3">
                <a
                  v-if="group.note.url"
                  :href="group.note.url"
                  class="inline-flex h-8 items-center gap-1.5 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
                >
                  <ManageIcon :name="group.note.icon ?? 'download'" :size="14" />
                  {{ group.note.label }}
                </a>
                <p class="text-[11px] text-fg-3">
                  {{ group.note.text }}
                </p>
              </div>
            </div>
          </FormSection>
        </div>

        <FormActions
          :processing="form.processing"
          :dirty="form.isDirty"
          submit-label="Save settings"
        />
      </form>

      <div v-else class="flex min-w-0 flex-1 flex-col p-4">
        <div
          class="flex items-center justify-between rounded border border-state-danger/35 bg-surface-2 px-3 py-2.5"
        >
          <div>
            <p class="text-[13px] text-fg-1">Reset everything to the shipped defaults</p>
            <p class="text-[11px] text-fg-3">
              Every pane, not one of them. Uploaded files stay on the disk in case another setting
              still points at them.
            </p>
          </div>
          <button
            type="button"
            class="h-8 shrink-0 rounded border border-state-danger/35 px-3 text-[13px] text-state-danger transition-colors hover:bg-state-danger/12"
            @click="resetAll"
          >
            Reset to defaults
          </button>
        </div>
      </div>
    </div>
  </ManageLayout>
</template>
