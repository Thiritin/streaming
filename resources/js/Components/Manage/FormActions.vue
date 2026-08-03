<script setup>
/**
 * Sticky footer for a form. Stays in view on long forms so Save is never scrolled away,
 * and reports unsaved changes rather than silently discarding them on navigation.
 */
defineProps({
  processing: { type: Boolean, default: false },
  dirty: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Save changes' },
});
</script>

<template>
  <div class="sticky bottom-0 flex items-center gap-2 border-t border-hairline bg-surface-1/95 px-3 py-2 backdrop-blur">
    <span v-if="dirty" class="text-[11px] text-state-warn">Unsaved changes</span>

    <div class="ml-auto flex items-center gap-2">
      <slot name="secondary" />

      <button
        type="submit"
        class="h-8 rounded bg-state-live px-3 text-[13px] font-medium text-surface-0 transition-opacity disabled:opacity-50"
        :disabled="processing"
      >
        {{ processing ? 'Saving…' : submitLabel }}
      </button>
    </div>
  </div>
</template>
