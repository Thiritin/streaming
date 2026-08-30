<script setup>
/**
 * The one-line confirmation after a subscription changes.
 *
 * A bell is a control with no visible result: the page looks the same afterwards and
 * whatever was promised happens hours later, somewhere else. This is the only moment
 * the viewer is looking, so it is also where they are told if nothing can reach them.
 */
import { onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';

const page = usePage();

const message = ref(null);
let timer = null;

function dismiss() {
  message.value = null;
  clearTimeout(timer);
}

watch(
  // The flash is cleared server-side after one render, so the same text twice in a row
  // arrives as two separate values and has to reset the timer rather than be ignored.
  () => page.props.flash?.toast,
  (next) => {
    if (!next) return;

    message.value = next;
    clearTimeout(timer);
    timer = setTimeout(dismiss, 6000);
  },
  { immediate: true },
);

onUnmounted(() => clearTimeout(timer));
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-3 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="translate-y-3 opacity-0"
  >
    <div
      v-if="message"
      class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center px-4"
      role="status"
      aria-live="polite"
    >
      <div class="pointer-events-auto flex max-w-md items-start gap-3 rounded-xl border border-primary-700 bg-primary-900 px-4 py-3 shadow-lg">
        <p class="text-sm text-primary-100">{{ message }}</p>
        <button
          type="button"
          class="-mr-1 shrink-0 rounded p-0.5 text-primary-400 hover:text-primary-100"
          aria-label="Dismiss"
          @click="dismiss"
        >
          <X class="size-4" :stroke-width="1.8" />
        </button>
      </div>
    </div>
  </Transition>
</template>
