<script setup>
/**
 * A description rendered from markdown.
 *
 * The HTML is produced and sanitised server-side by App\Support\Markdown (raw tags
 * stripped, unsafe link schemes dropped), which is why it can be bound with v-html here.
 * Never point this at a string that did not come through that renderer.
 *
 * Falls back to the plain text when no rendered HTML is available, so a description that
 * predates this, or an endpoint that does not send the HTML, still shows.
 */
defineProps({
  html: { type: String, default: null },
  text: { type: String, default: null },
});
</script>

<template>
  <div v-if="html" class="markdown" v-html="html" />
  <p v-else-if="text" class="whitespace-pre-wrap">{{ text }}</p>
</template>

<style scoped>
/*
 * Spacing only. Colour and size are inherited from wherever the description sits, so the
 * same abstract reads correctly on the dark player page and on a light card.
 */
.markdown :deep(p) {
  margin-bottom: 0.75em;
}

.markdown :deep(p:last-child) {
  margin-bottom: 0;
}

.markdown :deep(strong) {
  font-weight: 600;
}

.markdown :deep(em) {
  font-style: italic;
}

.markdown :deep(ul),
.markdown :deep(ol) {
  margin: 0 0 0.75em 1.25em;
  list-style-position: outside;
}

.markdown :deep(ul) {
  list-style-type: disc;
}

.markdown :deep(ol) {
  list-style-type: decimal;
}

.markdown :deep(li) {
  margin-bottom: 0.25em;
}

.markdown :deep(a) {
  text-decoration: underline;
  text-underline-offset: 2px;
}

.markdown :deep(h1),
.markdown :deep(h2),
.markdown :deep(h3),
.markdown :deep(h4) {
  margin-bottom: 0.5em;
  font-weight: 600;
}

.markdown :deep(code) {
  border-radius: 0.25rem;
  background-color: rgb(255 255 255 / 0.1);
  padding: 0.1em 0.3em;
  font-size: 0.9em;
}

.markdown :deep(blockquote) {
  margin-bottom: 0.75em;
  border-left: 2px solid currentColor;
  padding-left: 0.75em;
  opacity: 0.85;
}
</style>
