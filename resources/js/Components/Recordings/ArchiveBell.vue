<script setup>
/**
 * The bell beside the archive's search box.
 *
 * It opens the notification settings rather than toggling anything. A single hidden
 * flag behind a bell was the wrong promise: what people actually want to choose is
 * which of the two categories they get and where it is sent, and this is the only
 * entry point most of them will ever find.
 */
import { computed, ref } from 'vue';
import { BellRing } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import NotificationSettings from '@/Components/Notifications/NotificationSettings.vue';

const props = defineProps({
  notifications: { type: Object, required: true },
});

const open = ref(false);

// Filled when something would actually be sent: a category that is not off, and
// somewhere to send it. Anything less is a bell that promises what it cannot keep.
const active = computed(
  () =>
    props.notifications.channels.available.length > 0 &&
    props.notifications.categories.some((category) => category.scope !== 'off'),
);
</script>

<template>
  <button
    type="button"
    class="archive-bell"
    :class="{ 'archive-bell-on': active }"
    aria-label="Notification settings"
    title="Notification settings"
    @click="open = true"
  >
    <BellRing class="size-4" :stroke-width="1.8" :fill="active ? 'currentColor' : 'none'" />
  </button>

  <Modal :show="open" max-width="lg" @close="open = false">
    <div class="px-5 py-4 sm:px-6">
      <h2 class="mb-4 text-base font-semibold text-primary-50">Notifications</h2>
      <NotificationSettings :notifications="notifications" />
    </div>
  </Modal>
</template>

<style scoped>
.archive-bell {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.25rem;
  width: 2.25rem;
  flex: none;
  border-radius: 0.5rem;
  border: 1px solid var(--color-primary-800);
  background-color: color-mix(in oklch, var(--color-primary-900) 60%, transparent);
  color: var(--color-primary-300);
  transition: color 120ms ease, border-color 120ms ease, background-color 120ms ease;
}

.archive-bell:hover {
  color: var(--color-primary-100);
  border-color: var(--color-primary-700);
}

.archive-bell-on {
  color: var(--color-primary-50);
  border-color: var(--color-primary-500);
  background-color: color-mix(in oklch, var(--color-primary-500) 22%, transparent);
}
</style>
