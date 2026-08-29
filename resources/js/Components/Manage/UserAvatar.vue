<script setup>
/**
 * A person as a chip: their initials on a colour that is always theirs.
 *
 * There is no avatar image anywhere in the system - accounts arrive over OpenID with a
 * name and nothing else - so the point here is recognition at a glance rather than
 * likeness. The colour is a hash of the name, so the same person is the same colour on
 * every row of a grid that is read down a column, and a band of thirty rows can be
 * scanned for whose is whose without reading a single one.
 */
import { computed } from 'vue';

const props = defineProps({
  name: { type: String, default: null },
  size: { type: Number, default: 18 },
});

const initials = computed(() => {
  const parts = (props.name ?? '').trim().split(/\s+/).filter(Boolean);

  if (parts.length === 0) {
    return '?';
  }

  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});

/** Hue only, so every chip sits at the same weight against the surface behind it. */
const hue = computed(() => {
  let hash = 0;

  for (const character of props.name ?? '') {
    hash = (hash * 31 + character.charCodeAt(0)) % 360;
  }

  return hash;
});

const style = computed(() => ({
  width: `${props.size}px`,
  height: `${props.size}px`,
  fontSize: `${Math.round(props.size * 0.44)}px`,
  background: props.name ? `hsl(${hue.value} 45% 32%)` : 'transparent',
  color: props.name ? `hsl(${hue.value} 70% 88%)` : 'var(--fg-3, #888)',
  boxShadow: props.name ? 'none' : 'inset 0 0 0 1px currentColor',
}));
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center justify-center rounded-full font-semibold leading-none"
    :style="style"
    :title="name ?? undefined"
    aria-hidden="true"
  >
    {{ initials }}
  </span>
</template>
