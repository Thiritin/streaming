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
  <header class="flex min-h-14 shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-hairline px-3 py-2 md:flex-nowrap md:px-4 md:py-0">
    <div class="w-full min-w-0 md:w-auto md:flex-1">
      <h1 class="truncate text-[17px] font-semibold text-fg-1">{{ title }}</h1>
      <p v-if="subtitle" class="text-[12px] text-fg-3 md:truncate">{{ subtitle }}</p>
    </div>

    <div class="flex w-full flex-wrap items-center gap-1.5 md:ml-auto md:w-auto">
      <slot name="actions" />
      <ActionButton v-for="action in actions" :key="action.name" :action="action" />
    </div>
  </header>
</template>
