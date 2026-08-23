<template>
  <div ref="root" class="relative w-full sm:w-80">
    <form role="search" @submit.prevent="submit">
      <label class="sr-only" for="archive-search">Search the archive</label>
      <input
        id="archive-search"
        ref="input"
        v-model="query"
        type="search"
        autocomplete="off"
        role="combobox"
        aria-controls="archive-suggestions"
        :aria-expanded="open"
        placeholder="Search the archive"
        class="search-input"
        @input="onInput"
        @focus="onInput"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.esc="close"
      />
    </form>

    <ul
      v-if="open && suggestions.length"
      id="archive-suggestions"
      class="suggestions"
      role="listbox"
    >
      <li v-for="(suggestion, index) in suggestions" :key="suggestion.id" role="option" :aria-selected="index === cursor">
        <Link
          :href="suggestion.url"
          class="suggestion"
          :class="{ 'suggestion-active': index === cursor }"
          @mouseenter="cursor = index"
          @click="close"
        >
          <span class="suggestion-art">
            <img
              v-if="suggestion.thumbnail_url"
              :src="suggestion.thumbnail_url"
              alt=""
              loading="lazy"
              decoding="async"
              class="h-full w-full object-cover"
            />
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm text-white">{{ suggestion.title }}</span>
            <span class="block truncate text-xs text-primary-400">
              {{ [suggestion.source_name, suggestion.year].filter(Boolean).join(' · ') }}
            </span>
          </span>
        </Link>
      </li>

      <li>
        <button type="button" class="suggestion suggestion-all" @click="submit">
          See all results for "{{ query }}"
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'submit']);

const root = ref(null);
const input = ref(null);
const query = ref(props.modelValue ?? '');
const suggestions = ref([]);
const open = ref(false);
const cursor = ref(-1);

let debounce = null;
let controller = null;

watch(
  () => props.modelValue,
  (value) => {
    query.value = value ?? '';
  }
);

/*
 * The one place on the site that talks to the server outside Inertia, and it has
 * to be: this answers on every keystroke, and reloading the archive's props to
 * fill a dropdown would re-render the page under the viewer as they type.
 */
const fetchSuggestions = async (term) => {
    controller?.abort();
    controller = new AbortController();

    try {
        const response = await fetch(`${route('recordings.suggest')}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!response.ok) return;

        const data = await response.json();
        suggestions.value = data.suggestions ?? [];
        open.value = suggestions.value.length > 0;
        cursor.value = -1;
    } catch (error) {
        if (error.name !== 'AbortError') {
            suggestions.value = [];
            open.value = false;
        }
    }
};

const onInput = () => {
    emit('update:modelValue', query.value);
    clearTimeout(debounce);

    const term = query.value.trim();

    if (term.length < 2) {
        suggestions.value = [];
        open.value = false;
        return;
    }

    debounce = setTimeout(() => fetchSuggestions(term), 200);
};

const move = (delta) => {
    if (!open.value || !suggestions.value.length) return;

    const next = cursor.value + delta;
    cursor.value = (next + suggestions.value.length) % suggestions.value.length;
};

const close = () => {
    open.value = false;
    cursor.value = -1;
};

const submit = () => {
    // Enter on a highlighted suggestion opens it; otherwise it searches.
    const highlighted = suggestions.value[cursor.value];

    close();

    if (highlighted) {
        router.visit(highlighted.url);
        return;
    }

    emit('submit', query.value.trim());
};

const onDocumentClick = (event) => {
    if (root.value && !root.value.contains(event.target)) close();
};

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    clearTimeout(debounce);
    controller?.abort();
});
</script>

<style scoped>
@reference "../../../css/app.css";

.search-input {
  @apply w-full rounded-full border border-primary-700 bg-primary-950/60 px-4 py-2 text-sm text-white placeholder:text-primary-500 transition-colors;
}

.search-input:focus {
  @apply border-primary-400 outline-none ring-2 ring-primary-500/30;
}

.suggestions {
  @apply absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-white/10 bg-primary-950/95 py-1 shadow-2xl backdrop-blur;
}

.suggestion {
  @apply flex w-full items-center gap-3 px-3 py-2 text-left transition-colors;
}

.suggestion-active,
.suggestion:hover {
  @apply bg-white/8;
}

.suggestion-art {
  @apply block h-9 w-16 shrink-0 overflow-hidden rounded bg-primary-800;
}

.suggestion-all {
  @apply border-t border-white/10 text-sm text-primary-300;
}
</style>
