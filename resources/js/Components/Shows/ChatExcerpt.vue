<template>
  <section class="chat-excerpt" aria-label="Chat">
    <header class="chat-excerpt-head">
      <span class="chat-excerpt-label">Chat</span>
      <Link :href="chatHref" class="chat-excerpt-join">Join<span class="sr-only"> the chat</span> &rarr;</Link>
    </header>

    <ol v-if="lines.length" ref="list" class="chat-excerpt-list">
      <li v-for="line in lines" :key="line.id" class="chat-excerpt-line" :class="{ 'chat-excerpt-system': isSystem(line) }">
        <span v-if="!isSystem(line)" class="chat-excerpt-name" :style="{ color: line.color }">{{ line.name }}</span>
        <span class="chat-excerpt-body">{{ line.body }}</span>
      </li>
    </ol>

    <p v-else class="chat-excerpt-empty">No messages yet. Be the first.</p>
  </section>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
  /** { source_id, messages: [...] } as presented by MessagePresenter. */
  chat: { type: Object, default: () => ({ source_id: null, messages: [] }) },
  /** Slug of the featured show, used for the link into the full chat. */
  showSlug: { type: String, required: true },
  /** How many lines to keep client-side; the visible count is whatever fits. */
  max: { type: Number, default: 40 },
});

const lines = ref([...(props.chat.messages ?? [])].slice(-props.max));
const list = ref(null);

const chatHref = computed(() => route('show.view', props.showSlug));

const isSystem = (line) => line.type && line.type !== 'user';

const scrollToLatest = () => {
  nextTick(() => {
    if (list.value) list.value.scrollTop = list.value.scrollHeight;
  });
};

const push = (message) => {
  lines.value = [...lines.value, message].slice(-props.max);
  scrollToLatest();
};

// Chat is keyed by source, so the excerpt subscribes to the channel rather than
// the show; it keeps ticking over even as one show ends and the next begins.
const subscribe = (sourceId) => {
  if (!sourceId) return;
  Echo.channel(`chat.source.${sourceId}`).listen('.message', push);
};

const unsubscribe = (sourceId) => {
  if (sourceId) Echo.leave(`chat.source.${sourceId}`);
};

watch(() => props.chat.source_id, (next, previous) => {
  unsubscribe(previous);
  lines.value = [...(props.chat.messages ?? [])].slice(-props.max);
  subscribe(next);
  scrollToLatest();
});

onMounted(() => {
  subscribe(props.chat.source_id);
  scrollToLatest();
});

onUnmounted(() => unsubscribe(props.chat.source_id));
</script>

<style scoped>
@reference "../../../css/app.css";

.chat-excerpt {
  @apply flex min-h-0 flex-1 flex-col gap-2 border-t border-white/10 pt-3;
}

.chat-excerpt-head {
  @apply flex items-baseline justify-between;
}

.chat-excerpt-label {
  @apply text-[11px] font-semibold uppercase tracking-[0.14em] text-primary-400;
}

.chat-excerpt-join {
  @apply text-xs font-medium text-primary-300 transition-colors hover:text-white;
}

.chat-excerpt-join:focus-visible {
  @apply outline-none ring-2 ring-primary-400 rounded;
}

/* Grows into the space beside the player and scrolls inside it, so a busy channel
   fills the column instead of overflowing the hero. min-h-0 lets flex actually
   shrink the list; without it the overflow never kicks in. */
.chat-excerpt-list {
  @apply m-0 flex min-h-0 flex-1 list-none flex-col justify-end gap-1.5 overflow-y-auto p-0 text-sm leading-snug;
  scrollbar-width: thin;
  overscroll-behavior: contain;
}

.chat-excerpt-line {
  @apply text-primary-100;
}

.chat-excerpt-name {
  @apply font-semibold;
}

.chat-excerpt-name::after {
  content: ':';
  @apply text-primary-500;
}

.chat-excerpt-body {
  @apply ml-1.5 break-words text-primary-200/90;
}

.chat-excerpt-system .chat-excerpt-body {
  @apply ml-0 italic text-primary-400;
}

.chat-excerpt-empty {
  @apply text-sm text-primary-400;
}
</style>
