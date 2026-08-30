<script setup>
/**
 * An on/off switch. A real control rather than a styled div: it is a button with
 * role="switch", so it carries a checked state, takes focus and answers Space and
 * Enter without any of that having to be written here.
 */
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  label: { type: String, required: true },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const toggle = () => {
  if (!props.disabled) emit('update:modelValue', !props.modelValue);
};
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :aria-label="label"
    :disabled="disabled"
    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-hairline transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-state-live/40 disabled:cursor-not-allowed disabled:opacity-40"
    :class="modelValue ? 'bg-state-live' : 'bg-surface-3'"
    @click="toggle"
  >
    <span
      class="pointer-events-none size-3.5 rounded-full bg-white shadow transition-transform duration-150"
      :class="modelValue ? 'translate-x-[18px]' : 'translate-x-[3px]'"
    />
  </button>
</template>
