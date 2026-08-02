<script setup>
import ActionButton from './ActionButton.vue';

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: null },
  /** Server-declared page-level actions, e.g. "New Show". */
  actions: { type: Array, default: () => [] },
});
</script>

<template>
  <header class="flex h-14 shrink-0 items-center gap-3 border-b border-hairline px-4">
    <div class="min-w-0">
      <h1 class="truncate text-[17px] font-semibold text-fg-1">{{ title }}</h1>
      <p v-if="subtitle" class="truncate text-[12px] text-fg-3">{{ subtitle }}</p>
    </div>

    <div class="ml-auto flex items-center gap-1.5">
      <slot name="actions" />
      <ActionButton v-for="action in actions" :key="action.name" :action="action" />
    </div>
  </header>
</template>
