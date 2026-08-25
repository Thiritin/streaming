<script setup>
import { computed, ref, watch } from 'vue';

/**
 * Someone's picture from the identity provider, with their initial behind it.
 * A dead or blocked URL falls back rather than leaving a broken image, which is
 * the same handling the top bar does for the signed-in viewer's own avatar.
 */
const props = defineProps({
  name: { type: String, default: '' },
  src: { type: String, default: null },
  size: { type: String, default: 'size-9' },
});

const failed = ref(false);

// A list re-uses these components as it changes, so a failure recorded against
// one person must not follow the next one into the same slot.
watch(() => props.src, () => (failed.value = false));

const url = computed(() => (failed.value ? null : props.src || null));
const initial = computed(() => props.name?.trim()?.charAt(0)?.toUpperCase() || '?');
</script>

<template>
  <img
    v-if="url"
    :src="url"
    alt=""
    loading="lazy"
    referrerpolicy="no-referrer"
    class="shrink-0 rounded-full object-cover bg-primary-800"
    :class="size"
    @error="failed = true"
  />
  <div
    v-else
    class="shrink-0 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center"
    :class="size"
    aria-hidden="true"
  >
    <span class="text-white font-semibold text-sm">{{ initial }}</span>
  </div>
</template>
