<script setup>
/**
 * Uploads a file to POST /manage/uploads and keeps the stored path as the field value.
 *
 * The upload is its own Inertia request so the parent form is never submitted half-filled;
 * the endpoint flashes the path back and the field adopts it. Nothing here talks JSON.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';

const props = defineProps({
  modelValue: { type: String, default: null },
  /** Storage rules to apply, a key from config/manage.php */
  purpose: { type: String, required: true },
  previewUrl: { type: String, default: null },
  accept: { type: String, default: 'image/*' },
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const uploading = ref(false);
const preview = ref(props.previewUrl);

const upload = (event) => {
  const file = event.target.files?.[0];

  if (!file) {
    return;
  }

  uploadFile(file);
};

/**
 * Exposed so a caller that produced a file itself - the cut editor grabbing the frame on
 * screen - lands on the same endpoint and the same preview as a picked file, rather than
 * growing a second upload path that has to be kept in step with this one.
 */
const uploadFile = (file) => {
  uploading.value = true;

  router.post(
    route('manage.uploads.store'),
    { purpose: props.purpose, file },
    {
      forceFormData: true,
      preserveScroll: true,
      preserveState: true,
      onSuccess: (page) => {
        const uploaded = page.props.flash?.upload ?? page.flash?.upload;

        if (uploaded?.purpose === props.purpose) {
          emit('update:modelValue', uploaded.path);
          preview.value = uploaded.url ?? preview.value;
        }
      },
      onFinish: () => {
        uploading.value = false;

        if (input.value) {
          input.value.value = '';
        }
      },
    },
  );
};

defineExpose({ uploadFile, uploading });

const clear = () => {
  emit('update:modelValue', null);
  preview.value = null;
};

const button =
  'inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3';
</script>

<template>
  <div class="flex items-start gap-3">
    <div class="flex h-16 w-28 shrink-0 items-center justify-center overflow-hidden rounded border border-hairline bg-surface-2">
      <img v-if="preview" :src="preview" alt="" class="h-full w-full object-cover" />
      <ManageIcon v-else name="image" :size="18" class="text-fg-3" />
    </div>

    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-1.5">
        <button type="button" :class="button" :disabled="uploading" @click="input.click()">
          <ManageIcon name="image" :size="12" />
          {{ uploading ? 'Uploading…' : modelValue ? 'Replace' : 'Upload' }}
        </button>

        <button v-if="modelValue" type="button" :class="button" @click="clear">
          <ManageIcon name="x" :size="12" />
          Remove
        </button>
      </div>

      <p v-if="modelValue" class="max-w-72 truncate font-mono text-[11px] text-fg-3">{{ modelValue }}</p>

      <input ref="input" type="file" class="hidden" :accept="accept" @change="upload" />
    </div>
  </div>
</template>
