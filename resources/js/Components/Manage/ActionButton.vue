<script setup>
/**
 * Renders one server-declared action.
 *
 * The client decides nothing: the server has already resolved whether the action is
 * offered at all, whether it is disabled and why, what the confirm modal says, and which
 * fields to collect first. GET actions are Inertia links; everything else submits a
 * form and lands back on the page with a flashed toast.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneButton } from './tones.js';

const props = defineProps({
  action: { type: Object, required: true },
  /** Extra payload merged into the request, e.g. { ids: [1, 2] } for bulk actions. */
  data: { type: Object, default: () => ({}) },
  iconOnly: { type: Boolean, default: false },
});

const open = ref(false);
const processing = ref(false);
const form = reactive({});

const classes = computed(() => resolve(toneButton, props.action.tone, 'info'));
const needsDialog = computed(() => Boolean(props.action.confirm || props.action.fields));
const disabled = computed(() => Boolean(props.action.disabledReason));

watch(open, (isOpen) => {
  if (!isOpen) {
    return;
  }

  for (const field of props.action.fields ?? []) {
    form[field.key] = field.default ?? '';
  }
});

const submit = () => {
  const payload = { ...props.data, ...form };

  processing.value = true;

  // router.visit rather than router[method]: router.delete takes (url, options) with no
  // data argument, so a delete called like the others dropped both the payload and the
  // options - bulk delete arrived without its ids and the dialog never closed.
  router.visit(props.action.url, {
    method: props.action.method,
    data: payload,
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      processing.value = false;
      open.value = false;
    },
  });
};

const activate = () => {
  if (disabled.value) {
    return;
  }

  if (needsDialog.value) {
    open.value = true;

    return;
  }

  submit();
};

const base =
  'inline-flex h-7 items-center gap-1.5 rounded border px-2 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
  <a
    v-if="action.method === 'get' && action.newTab"
    :href="action.url"
    target="_blank"
    rel="noopener"
    :class="[base, classes]"
    :title="action.label"
  >
    <ManageIcon v-if="action.icon" :name="action.icon" />
    <span v-if="!iconOnly">{{ action.label }}</span>
  </a>

  <Link
    v-else-if="action.method === 'get'"
    :href="action.url"
    :class="[base, classes]"
    :title="action.label"
  >
    <ManageIcon v-if="action.icon" :name="action.icon" />
    <span v-if="!iconOnly">{{ action.label }}</span>
  </Link>

  <button
    v-else
    type="button"
    :class="[base, classes]"
    :disabled="disabled || processing"
    :title="action.disabledReason ?? action.label"
    @click.stop="activate"
  >
    <ManageIcon v-if="action.icon" :name="action.icon" />
    <span v-if="!iconOnly">{{ action.label }}</span>
  </button>

  <Dialog v-model:open="open">
    <DialogContent class="border-hairline bg-surface-1 text-fg-1">
      <DialogHeader>
        <DialogTitle class="text-base">{{ action.confirm?.heading ?? action.label }}</DialogTitle>
        <DialogDescription v-if="action.confirm?.description" class="text-[13px] text-fg-2">
          {{ action.confirm.description }}
        </DialogDescription>
      </DialogHeader>

      <div v-if="action.fields" class="flex flex-col gap-3">
        <label v-for="field in action.fields" :key="field.key" class="flex flex-col gap-1">
          <span class="text-[11px] font-medium uppercase tracking-wide text-fg-2">{{ field.label }}</span>

          <select
            v-if="field.type === 'select'"
            v-model="form[field.key]"
            class="h-8 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1"
            :required="field.required"
          >
            <option v-for="option in field.options" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>

          <textarea
            v-else-if="field.type === 'textarea'"
            v-model="form[field.key]"
            rows="3"
            class="rounded border border-hairline bg-surface-2 px-2 py-1.5 text-[13px] text-fg-1"
            :required="field.required"
          />

          <input
            v-else
            v-model="form[field.key]"
            :type="field.type === 'number' ? 'number' : 'text'"
            class="h-8 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1"
            :required="field.required"
          />

          <span v-if="field.helper" class="text-[11px] text-fg-3">{{ field.helper }}</span>
        </label>
      </div>

      <DialogFooter>
        <button
          type="button"
          class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-2 transition-colors hover:bg-surface-3"
          @click="open = false"
        >
          Cancel
        </button>
        <button
          type="button"
          class="h-8 rounded px-3 text-[13px] font-medium text-surface-0"
          :class="action.tone === 'danger' ? 'bg-state-danger' : 'bg-state-live'"
          :disabled="processing"
          @click="submit"
        >
          {{ action.confirm?.submit ?? action.label }}
        </button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
