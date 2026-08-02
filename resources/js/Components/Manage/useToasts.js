import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Turns Inertia's flash data into a queue of dismissable toasts.
 *
 * `flash` is a top-level key on the page object, not a prop (see Response::toResponse in
 * inertia-laravel), which is exactly why it never lands in the browser's history state:
 * navigating back cannot replay an old toast.
 *
 * Every mutating manage action flashes one, with the same title and body the Filament
 * notification used, so behaviour is comparable and testable.
 */
const toasts = ref([]);
let nextId = 1;
let installed = false;

export function pushToast(toast) {
  if (!toast?.title) {
    return;
  }

  const entry = { id: nextId++, ...toast };
  toasts.value = [...toasts.value, entry];

  window.setTimeout(() => dismissToast(entry.id), toast.tone === 'danger' ? 9000 : 5000);
}

export function dismissToast(id) {
  toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

export function useToasts() {
  if (!installed) {
    installed = true;
    const page = usePage();

    watch(
      () => page.flash?.toast,
      (toast) => pushToast(toast),
      { immediate: true, deep: true },
    );
  }

  return { toasts, dismissToast, pushToast };
}
