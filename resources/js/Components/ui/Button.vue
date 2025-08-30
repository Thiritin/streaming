<script setup>
import { cn } from '@/lib/utils'

const props = defineProps({
  variant: { 
    type: String, 
    default: 'default',
    validator: (value) => ['default', 'secondary', 'outline', 'ghost'].includes(value)
  },
  size: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'sm', 'lg', 'icon'].includes(value)
  },
  disabled: { type: Boolean, default: false },
  class: { type: String, default: '' }
})

const variants = {
  default: 'bg-primary-600 text-white hover:bg-primary-700',
  secondary: 'bg-primary-800 text-primary-100 hover:bg-primary-700',
  outline: 'border border-primary-600 bg-transparent text-primary-100 hover:bg-primary-800',
  ghost: 'text-primary-100 hover:bg-primary-800'
}

const sizes = {
  default: 'h-10 px-4 py-2',
  sm: 'h-9 rounded-md px-3',
  lg: 'h-11 rounded-md px-8',
  icon: 'h-10 w-10'
}
</script>

<template>
  <button
    :disabled="disabled"
    :class="cn(
      'inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-primary-950 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
      variants[variant],
      sizes[size],
      props.class
    )"
    @click="$emit('click', $event)"
  >
    <slot />
  </button>
</template>