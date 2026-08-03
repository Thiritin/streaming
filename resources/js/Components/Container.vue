<script setup>
// Props for optional custom padding
const props = defineProps({
  padding: {
    type: String,
    default: 'px-4 sm:px-6 lg:px-8' // Standard responsive padding
  },
  noPadding: {
    type: Boolean,
    default: false
  }
});
</script>

<template>
  <div :class="['w-full mx-auto max-w-page', noPadding ? '' : padding]">
    <slot />
  </div>
</template>