<script setup>
/**
 * The installation's banner, set in /manage > Settings > Announcement and rendered
 * on the front page only.
 *
 * A dismissal is remembered in localStorage against the announcement's id, a hash of
 * its text, so an edit brings the banner back for everyone who closed the last one.
 */
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import MarkdownText from '@/Components/MarkdownText.vue';

const STORAGE_KEY = 'announcement.dismissed';

const props = defineProps({
  /** As shaped by App\Support\Announcement::current(), or null when there is none. */
  announcement: { type: Object, default: null },
});

const announcement = computed(() => props.announcement);

const dismissedId = ref(read());

function read() {
  try {
    return window.localStorage.getItem(STORAGE_KEY);
  } catch {
    // Private windows and blocked site data throw; the banner then shows every time.
    return null;
  }
}

const dismiss = () => {
  const id = announcement.value?.id;

  if (!id) return;

  dismissedId.value = id;

  try {
    window.localStorage.setItem(STORAGE_KEY, id);
  } catch {
    // Nothing to recover from: it stays closed for this page view either way.
  }
};

// A new announcement is a different id, so a stale dismissal stops applying.
watch(() => announcement.value?.id, () => {
  dismissedId.value = read();
});

const visible = computed(() => {
  const current = announcement.value;

  if (!current) return false;

  return !(current.dismissible && dismissedId.value === current.id);
});

// A path stays inside the app as an Inertia visit; anything else opens in a new tab.
const link = computed(() => announcement.value?.link ?? null);
const internal = computed(() => !!link.value && link.value.url.startsWith('/'));

const tone = computed(() => ({
  info: {
    wrap: 'bg-primary-800/70 border-primary-700 text-primary-100',
    icon: 'text-primary-300',
    button: 'text-primary-300 hover:text-white hover:bg-primary-700/60',
    link: 'border-primary-500/60 hover:bg-primary-700/60',
  },
  warning: {
    wrap: 'bg-amber-500/15 border-amber-500/40 text-amber-50',
    icon: 'text-amber-300',
    button: 'text-amber-200 hover:text-white hover:bg-amber-500/20',
    link: 'border-amber-400/50 hover:bg-amber-500/20',
  },
  critical: {
    wrap: 'bg-red-500/15 border-red-500/45 text-red-50',
    icon: 'text-red-300',
    button: 'text-red-200 hover:text-white hover:bg-red-500/20',
    link: 'border-red-400/55 hover:bg-red-500/20',
  },
}[announcement.value?.level] ?? {
  wrap: 'bg-primary-800/70 border-primary-700 text-primary-100',
  icon: 'text-primary-300',
  button: 'text-primary-300 hover:text-white hover:bg-primary-700/60',
  link: 'border-primary-500/60 hover:bg-primary-700/60',
}));
</script>

<template>
  <div
    v-if="visible"
    class="border-b"
    :class="tone.wrap"
    role="status"
    aria-live="polite"
  >
    <div class="mx-auto flex max-w-page items-start gap-3 px-4 py-3 sm:px-6 lg:px-8">
      <svg
        class="mt-0.5 size-5 shrink-0"
        :class="tone.icon"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path
          v-if="announcement.level === 'info'"
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
        />
        <path
          v-else
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
        />
      </svg>

      <div class="min-w-0 flex-1 text-sm">
        <p v-if="announcement.title" class="font-semibold">{{ announcement.title }}</p>
        <MarkdownText
          :html="announcement.html"
          :text="announcement.text"
          :class="announcement.title ? 'mt-0.5 opacity-90' : ''"
        />

        <component
          :is="internal ? Link : 'a'"
          v-if="link"
          :href="link.url"
          :target="internal ? null : '_blank'"
          :rel="internal ? null : 'noopener'"
          class="mt-2 inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors"
          :class="tone.link"
        >
          {{ link.label }}
          <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </component>
      </div>

      <button
        v-if="announcement.dismissible"
        type="button"
        class="-mr-1 shrink-0 rounded-md p-1 transition-colors"
        :class="tone.button"
        aria-label="Dismiss announcement"
        @click="dismiss"
      >
        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
  </div>
</template>
