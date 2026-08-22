<script setup>
/**
 * Left sidebar. From lg up it is permanent: always 240px, always labelled, no collapse.
 *
 * An icon rail saves 180px and costs a guess on every click; this panel is used to edit
 * things, not to watch a wall, so the labels stay. Fixed width also means the content
 * column never reflows mid-session.
 *
 * Below lg it is the same list, but off-canvas: it slides in over the content and the
 * layout closes it again on the next visit. Rows are taller there because a finger is
 * not a cursor.
 *
 * Groups and badges come from App\Support\Manage\Navigation, which drops items whose
 * route does not exist yet - that is how rebuild phases add modules without touching
 * this component.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneBadge } from './tones.js';

const props = defineProps({
  groups: { type: Array, default: () => [] },
  open: { type: Boolean, default: false },
});

defineEmits(['close']);

const page = usePage();

const path = (url) => new URL(url, window.location.origin).pathname.replace(/\/+$/, '') || '/';

const matches = (current, target) => current === target
  || current.startsWith(target === '/' ? '/' : `${target}/`);

/**
 * Only the deepest matching item lights up. A plain prefix match lit every ancestor
 * too, so /manage/shows/planner also highlighted Shows and Dashboard (/manage). The
 * longest match still keeps a detail page on its section, e.g. /manage/servers/3
 * highlights Servers.
 */
const activeRoute = computed(() => {
  const current = path(page.url.split('?')[0]);

  return props.groups
    .flatMap((group) => group.items)
    .map((item) => ({ route: item.route, target: path(item.url) }))
    .filter((item) => matches(current, item.target))
    .sort((a, b) => b.target.length - a.target.length)[0]?.route ?? null;
});

const isActive = (item) => item.route === activeRoute.value;

/*
 * Off-canvas and shut, the panel is still in the document, so it stays out of the tab
 * order until it is open. From lg up it is never off-canvas and never inert.
 */
const wide = ref(true);
let query = null;
const onChange = (event) => (wide.value = event.matches);

onMounted(() => {
  query = window.matchMedia('(min-width: 1024px)');
  wide.value = query.matches;
  query.addEventListener('change', onChange);
});

onBeforeUnmount(() => query?.removeEventListener('change', onChange));

const hidden = computed(() => !wide.value && !props.open);
</script>

<template>
  <nav
    class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 flex-col overflow-y-auto border-r border-hairline bg-surface-1 transition-transform lg:static lg:z-auto lg:w-60 lg:translate-x-0"
    :class="open ? 'translate-x-0 shadow-2xl' : '-translate-x-full'"
    :inert="hidden || null"
    aria-label="Manage navigation"
  >
    <div class="flex h-12 items-center justify-between border-b border-hairline px-4 lg:hidden">
      <span class="text-[12px] font-semibold uppercase tracking-[0.12em] text-fg-3">Menu</span>
      <button
        type="button"
        class="-mr-2 inline-flex size-9 items-center justify-center rounded text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg-1"
        aria-label="Close navigation"
        @click="$emit('close')"
      >
        <ManageIcon name="x" :size="18" />
      </button>
    </div>

    <div v-for="group in groups" :key="group.label" class="py-2">
      <p class="px-4 pb-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3">
        {{ group.label }}
      </p>

      <Link
        v-for="item in group.items"
        :key="item.route"
        :href="item.url"
        class="relative flex h-11 items-center gap-2.5 px-4 text-[14px] transition-colors lg:h-9 lg:text-[13px]"
        :class="isActive(item)
          ? 'bg-state-live/10 font-medium text-state-live'
          : 'text-fg-2 hover:bg-surface-2 hover:text-fg-1'"
        @click="$emit('close')"
      >
        <span
          v-if="isActive(item)"
          class="absolute top-1 bottom-1 left-0 w-0.5 rounded-r bg-state-live"
          aria-hidden="true"
        />
        <ManageIcon :name="item.icon" :size="16" class="shrink-0" />
        <span class="flex-1 truncate">{{ item.label }}</span>
        <span
          v-if="item.badge"
          class="rounded px-1 text-[10px] font-medium ring-1 ring-inset"
          :class="resolve(toneBadge, item.badge.tone)"
        >{{ item.badge.label }}</span>
      </Link>
    </div>
  </nav>
</template>
