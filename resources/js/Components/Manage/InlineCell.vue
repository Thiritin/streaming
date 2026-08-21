<script setup>
/**
 * One editable cell, for the inline-edit mode of DataTable.
 *
 * Knows nothing about the domain: the server declared the type, the label, the options
 * and whether this particular row may change it. Saving happens on `change` rather than
 * on every keystroke, so a half-typed date is never sent.
 */
import { computed } from 'vue';

const props = defineProps({
  field: { type: Object, required: true },
  value: { type: [String, Number], default: '' },
  /** null, 'saving', 'saved' or 'error'; drives the border only. */
  state: { type: String, default: null },
});

const emit = defineEmits(['save']);

const border = computed(
  () =>
    ({
      saving: 'border-state-warn/50',
      saved: 'border-state-ok/60',
      error: 'border-state-danger/60',
    })[props.state] ?? 'border-hairline',
);

const control = computed(() => [
  'h-6 w-full rounded border bg-surface-2 px-1 text-[12px] text-fg-1 outline-none focus:border-state-live/50 disabled:opacity-50',
  border.value,
]);
</script>

<template>
  <select
    v-if="field.type === 'select'"
    :class="[control, 'max-w-44']"
    :value="value"
    :disabled="Boolean(field.disabled)"
    :title="field.disabled ?? field.label"
    :aria-label="field.label"
    @click.stop
    @change="emit('save', $event.target.value)"
  >
    <option v-for="option in field.options ?? []" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </select>

  <input
    v-else
    :class="[control, 'max-w-52 tabular-nums']"
    :type="field.type === 'datetime' ? 'datetime-local' : 'text'"
    :value="value"
    :disabled="Boolean(field.disabled)"
    :title="field.disabled ?? field.label"
    :aria-label="field.label"
    @click.stop
    @change="emit('save', $event.target.value)"
  />
</template>
