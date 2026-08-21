<script setup>
/**
 * One field, laid out as a row: label on the left, control on the right.
 *
 * Two-column forms fit more per screen but make an operator's eye jump; a single column of
 * label/control rows reads straight down and keeps every helper text directly under the
 * thing it explains. The label column is a fixed width so controls line up down the form.
 *
 * A read-only field renders as text rather than a disabled input: Filament used
 * Placeholder for exactly these (created_at, viewer counts) and a greyed-out box invites
 * clicking something that can never change.
 *
 * The row is a <label> only when it owns the control, so the caption focuses it. A slotted
 * field is a <div>: a label forwards every click inside it to its first labelable
 * descendant, which would fire a slotted button twice and cancel its own toggle.
 */
import { useSlots } from 'vue';

defineProps({
  label: { type: String, required: true },
  modelValue: { type: [String, Number, Boolean, null], default: null },
  type: { type: String, default: 'text' },
  /** [{ value, label }] for type="select" */
  options: { type: Array, default: () => [] },
  helper: { type: String, default: null },
  error: { type: String, default: null },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  placeholder: { type: String, default: null },
  min: { type: [String, Number], default: null },
  max: { type: [String, Number], default: null },
  step: { type: [String, Number], default: null },
  mono: { type: Boolean, default: false },
  /** Cap the control width for fields whose content is always short. */
  narrow: { type: Boolean, default: false },
  /** Textarea height, for a field that expects paragraphs rather than a line. */
  rows: { type: [String, Number], default: 3 },
});

defineEmits(['update:modelValue']);

const slots = useSlots();
const row = () => (slots.default ? 'div' : 'label');

const control =
  'h-8 w-full rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1 outline-none transition-colors focus:border-state-live/50 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
  <component :is="row()" class="grid grid-cols-1 items-baseline gap-1 py-1.5 sm:grid-cols-[13rem_minmax(0,1fr)] sm:gap-4">
    <span class="flex items-center gap-1 pt-1.5 text-[12px] font-medium text-fg-2 sm:justify-end sm:text-right">
      {{ label }}
      <span v-if="required" class="text-state-danger" aria-hidden="true">*</span>
    </span>

    <div class="min-w-0" :class="narrow ? 'sm:max-w-56' : ''">
      <slot>
        <span v-if="readonly" class="flex h-8 items-center text-[13px] text-fg-1" :class="mono ? 'font-mono' : ''">
          {{ modelValue ?? '—' }}
        </span>

        <select
          v-else-if="type === 'select'"
          :value="modelValue"
          :class="control"
          :disabled="disabled"
          @change="$emit('update:modelValue', $event.target.value)"
        >
          <option v-for="option in options" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>

        <span v-else-if="type === 'checkbox'" class="flex h-8 items-center">
          <input
            type="checkbox"
            class="size-4 accent-state-live"
            :checked="Boolean(modelValue)"
            :disabled="disabled"
            @change="$emit('update:modelValue', $event.target.checked)"
          />
        </span>

        <textarea
          v-else-if="type === 'textarea'"
          :value="modelValue"
          :rows="rows"
          class="w-full rounded border border-hairline bg-surface-2 px-2 py-1.5 text-[13px] text-fg-1 outline-none focus:border-state-live/50"
          :disabled="disabled"
          :placeholder="placeholder"
          @input="$emit('update:modelValue', $event.target.value)"
        />

        <input
          v-else
          :type="type"
          :value="modelValue"
          :class="[control, mono ? 'font-mono' : '']"
          :disabled="disabled"
          :placeholder="placeholder"
          :min="min"
          :max="max"
          :step="step"
          @input="$emit('update:modelValue', type === 'number' ? ($event.target.value === '' ? null : Number($event.target.value)) : $event.target.value)"
        />
      </slot>

      <p v-if="error" class="mt-1 text-[11px] text-state-danger">{{ error }}</p>
      <p v-else-if="helper" class="mt-1 text-[11px] text-fg-3">{{ helper }}</p>
    </div>
  </component>
</template>
