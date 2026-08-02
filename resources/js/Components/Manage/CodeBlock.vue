<script setup>
/**
 * A generated config or script: mono, scrollable, copyable, with optional wrapping.
 *
 * No syntax highlighting on purpose. These are shell scripts and nginx/Caddy/SRS configs
 * an operator pastes onto a box; a highlighter would add a dependency and a chance of
 * mangling the text that gets copied.
 */
import { ref } from 'vue';
import ManageIcon from './ManageIcon.vue';

const props = defineProps({
  content: { type: String, required: true },
  filename: { type: String, default: null },
  downloadUrl: { type: String, default: null },
});

const wrap = ref(false);
const copied = ref(false);

const copy = async () => {
  try {
    await navigator.clipboard.writeText(props.content);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 1500);
  } catch {
    // Clipboard needs a secure context; nothing to recover from.
  }
};

const lines = () => props.content.split('\n').length;

const button =
  'inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3';
</script>

<template>
  <div class="overflow-hidden rounded border border-hairline bg-surface-2">
    <div class="flex items-center gap-2 border-b border-hairline px-3 py-1.5">
      <span v-if="filename" class="font-mono text-[12px] text-fg-2">{{ filename }}</span>
      <span class="text-[11px] text-fg-3 tabular-nums">{{ lines() }} lines</span>

      <div class="ml-auto flex items-center gap-1.5">
        <button type="button" :class="button" @click="wrap = !wrap">
          {{ wrap ? 'No wrap' : 'Wrap' }}
        </button>
        <a v-if="downloadUrl" :href="downloadUrl" :class="button">
          <ManageIcon name="download" />
          Download
        </a>
        <button type="button" :class="button" @click="copy">
          <ManageIcon :name="copied ? 'check' : 'copy'" />
          {{ copied ? 'Copied' : 'Copy' }}
        </button>
      </div>
    </div>

    <pre
      class="max-h-[60vh] overflow-auto px-3 py-2 font-mono text-[12px] leading-relaxed text-fg-1"
      :class="wrap ? 'whitespace-pre-wrap break-words' : 'whitespace-pre'"
    >{{ content }}</pre>
  </div>
</template>
