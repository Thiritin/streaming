<script setup>
/**
 * Click-to-copy value, replacing the inline `onclick="navigator.clipboard..."` HTML the
 * Filament source form rendered through a Placeholder.
 *
 * `masked` hides the value until asked for, so a stream key is not readable over someone's
 * shoulder while the form is open.
 */
import { ref } from 'vue';
import ManageIcon from './ManageIcon.vue';

const props = defineProps({
  value: { type: String, default: null },
  masked: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Will be generated on save' },
});

const copied = ref(false);
const revealed = ref(!props.masked);

const copy = async () => {
  if (!props.value) {
    return;
  }

  try {
    await navigator.clipboard.writeText(props.value);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 1500);
  } catch {
    // Clipboard needs a secure context; nothing to recover from.
  }
};

const button = 'text-fg-3 transition-colors hover:text-fg-1';
</script>

<template>
  <div v-if="value" class="flex h-8 items-center gap-2 rounded border border-hairline bg-surface-2 px-2">
    <code class="min-w-0 flex-1 truncate font-mono text-[12px] text-fg-1">
      {{ revealed ? value : '•'.repeat(Math.min(value.length, 32)) }}
    </code>

    <button v-if="masked" type="button" :class="button" :title="revealed ? 'Hide' : 'Reveal'" @click="revealed = !revealed">
      <ManageIcon :name="revealed ? 'lock' : 'eye'" :size="13" />
    </button>

    <button type="button" :class="button" :title="copied ? 'Copied' : 'Copy'" @click="copy">
      <ManageIcon :name="copied ? 'check' : 'copy'" :size="13" />
    </button>
  </div>

  <span v-else class="flex h-8 items-center text-[13px] text-fg-3">{{ placeholder }}</span>
</template>
