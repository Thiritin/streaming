<script setup>
import { useVModel } from '@vueuse/core'
import { cn } from '@/lib/utils'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  rows: { type: Number, default: 3 },
  maxlength: { type: Number },
  class: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'keydown', 'keyup', 'mouseup', 'touchend'])

const modelValue = useVModel(props, 'modelValue', emit)
</script>

<template>
  <textarea
    v-model="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :readonly="readonly"
    :rows="rows"
    :maxlength="maxlength"
    @keydown="$emit('keydown', $event)"
    @keyup="$emit('keyup', $event)"
    @mouseup="$emit('mouseup', $event)"
    @touchend="$emit('touchend', $event)"
    :class="cn(
      'flex min-h-[80px] w-full rounded-lg border border-primary-800 bg-primary-950 px-3 py-2 text-sm text-primary-100 ring-offset-primary-950 placeholder:text-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
      props.class
    )"
  />
</template>