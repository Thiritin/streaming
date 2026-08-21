<script setup>
/**
 * The Feedback entry point in the top bar, and its twin in the mobile menu.
 *
 * Carries its own dialog rather than taking one from the layout: there is only ever
 * one of these open, and a button that owns its dialog can be dropped anywhere
 * without the page it lands on having to hold state for it.
 */
import { ref } from 'vue';
import FeedbackDialog from './FeedbackDialog.vue';

defineProps({
  /** 'nav' for the top bar, 'menu' for the mobile drawer row. */
  variant: { type: String, default: 'nav' },
});

const open = ref(false);
</script>

<template>
  <button
    v-if="variant === 'nav'"
    type="button"
    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-primary-300 transition-colors hover:bg-primary-800 hover:text-white"
    @click="open = true"
  >
    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
      />
    </svg>
    <span class="hidden lg:inline">Feedback</span>
  </button>

  <button
    v-else
    type="button"
    class="block w-full border-l-4 border-transparent py-2 pl-3 pr-4 text-left text-base font-medium text-primary-400 transition duration-(--dur-fast) ease-in-out hover:border-primary-600 hover:bg-primary-700 hover:text-primary-200"
    @click="open = true"
  >
    Feedback
  </button>

  <FeedbackDialog v-model:open="open" type="feedback" />
</template>
