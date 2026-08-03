<script setup>
/**
 * Permanent left sidebar. Always 240px, always labelled, no collapse.
 *
 * An icon rail saves 180px and costs a guess on every click; this panel is used to edit
 * things, not to watch a wall, so the labels stay. Fixed width also means the content
 * column never reflows mid-session.
 *
 * Groups and badges come from App\Support\Manage\Navigation, which drops items whose
 * route does not exist yet - that is how rebuild phases add modules without touching
 * this component.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneBadge } from './tones.js';

const props = defineProps({
  groups: { type: Array, default: () => [] },
});

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
</script>

<template>
  <nav
    class="flex w-60 shrink-0 flex-col overflow-y-auto border-r border-hairline bg-surface-1"
    aria-label="Manage navigation"
  >
    <div v-for="group in groups" :key="group.label" class="py-2">
      <p class="px-4 pb-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3">
        {{ group.label }}
      </p>

      <Link
        v-for="item in group.items"
        :key="item.route"
        :href="item.url"
        class="relative flex h-9 items-center gap-2.5 px-4 text-[13px] transition-colors"
        :class="isActive(item)
          ? 'bg-state-live/10 font-medium text-state-live'
          : 'text-fg-2 hover:bg-surface-2 hover:text-fg-1'"
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
