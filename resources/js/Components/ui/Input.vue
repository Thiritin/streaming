<script setup>
import { useVModel } from '@vueuse/core'
import { cn } from '@/lib/utils'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  class: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'click'])

const modelValue = useVModel(props, 'modelValue', emit)
</script>

<template>
  <input
    v-model="modelValue"
    :type="type"
    :placeholder="placeholder"
    :disabled="disabled"
    :readonly="readonly"
    @click="$emit('click', $event)"
    :class="cn(
      'flex h-10 w-full rounded-md border border-primary-600 bg-primary-950 px-3 py-2 text-sm text-primary-100 ring-offset-primary-950 placeholder:text-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
      props.class
    )"
  />
</template>