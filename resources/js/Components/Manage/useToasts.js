import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Turns Inertia's flash data into a queue of dismissable toasts.
 *
 * `flash` is a top-level key on the page object, not a prop (see Response::toResponse in
 * inertia-laravel), which is exactly why it never lands in the browser's history state:
 * navigating back cannot replay an old toast.
 *
 * It is read off the router's success event rather than `usePage()`: that composable
 * exposes a fixed list of page keys (props, url, component, version, ...) and `flash` is
 * not among them in @inertiajs/vue3 2.x, so watching it there never fires. The event
 * carries the raw page object, which has it.
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

    // Every Inertia response, including partial reloads and redirects after a POST.
    router.on('success', (event) => pushToast(event.detail.page?.flash?.toast));

    // The first page is rendered from the payload in the root element and fires no
    // event, so an action that redirected into a full page load is picked up here.
    try {
      const initial = JSON.parse(document.getElementById('app')?.dataset.page || '{}');
      pushToast(initial?.flash?.toast);
    } catch {
      // A malformed payload is not worth breaking the panel over.
    }
  }

  return { toasts, dismissToast, pushToast };
}
