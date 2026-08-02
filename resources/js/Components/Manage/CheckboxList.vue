<script setup>
/**
 * Multi-select as a grid of checkboxes, replacing Filament's CheckboxList.
 *
 * Used for access restriction, where an empty selection means "public" - so the empty
 * state says that out loud instead of leaving the operator to infer it.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  columns: { type: Number, default: 2 },
  emptyLabel: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const toggle = (value) => {
  emit(
    'update:modelValue',
    props.modelValue.includes(value)
      ? props.modelValue.filter((item) => item !== value)
      : [...props.modelValue, value],
  );
};

const gridClass = { 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3' };
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div class="grid grid-cols-1 gap-x-4 gap-y-1" :class="gridClass[columns] ?? gridClass[2]">
      <label
        v-for="option in options"
        :key="option.value"
        class="flex cursor-pointer items-center gap-2 text-[13px] text-fg-1"
      >
        <input
          type="checkbox"
          class="size-3.5 accent-state-live"
          :checked="modelValue.includes(option.value)"
          @change="toggle(option.value)"
        />
        {{ option.label }}
      </label>
    </div>

    <p v-if="emptyLabel && !modelValue.length" class="text-[11px] text-state-ok">{{ emptyLabel }}</p>
  </div>
</template>
