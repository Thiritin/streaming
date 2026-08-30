<script setup>
/**
 * The settings menu, shared by the generated panes and by the settings areas whose
 * contents are rows - Events, Categories, the sign-in providers.
 *
 * Three sections rather than one flat list, with the headings the rail 256px to the
 * left already uses, so the two navs read as one system. Rows are one line: the blurbs
 * were what made them 57px, and six of the fifteen restated the label above them.
 *
 * From lg the <nav> is both the sticky element and the scroller. It is a stretched flex
 * item, so its height is the visible area rather than its content's, and pinning it
 * without that bound left the tail of the list below the fold with nothing able to
 * reach it: scrolling the page moved the form and left the pinned menu where it was.
 *
 * Below lg it is a disclosure instead. It was a chip strip whose content ran to 1779px
 * inside a 390px window with every scrollbar suppressed and the active chip never
 * scrolled into view, so a phone could not say which pane it was on. The button says it.
 */
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';

const props = defineProps({
  /** { sections: [{ heading, items }], reset: { key, label, url } | null } */
  navigation: { type: Object, default: () => ({ sections: [], reset: null }) },
  active: { type: String, required: true },
});

const sections = computed(() => props.navigation.sections ?? []);
const reset = computed(() => props.navigation.reset ?? null);

const current = computed(() => {
  for (const section of sections.value) {
    const found = section.items.find((item) => item.key === props.active);

    if (found) return found;
  }

  return props.active === reset.value?.key ? reset.value : null;
});

const open = ref(false);

// A visit is the disclosure's job done; leaving it open would cover the pane it opened.
watch(() => props.active, () => (open.value = false));
</script>

<template>
  <nav
    class="sticky top-0 z-10 shrink-0 border-b border-hairline bg-surface-0 lg:w-64 lg:overflow-y-auto lg:border-r lg:border-b-0"
    aria-label="Settings sections"
  >
    <button
      type="button"
      class="flex w-full items-center gap-2.5 px-4 py-3 text-left lg:hidden"
      :aria-expanded="open"
      @click="open = !open"
    >
      <ManageIcon v-if="current" :name="current.icon ?? 'cog'" :size="16" class="shrink-0 text-state-live" />
      <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-fg-1">
        {{ current?.label ?? 'Settings' }}
      </span>
      <ManageIcon :name="open ? 'chevron-up' : 'chevron-down'" :size="16" class="shrink-0 text-fg-3" />
    </button>

    <div class="p-2" :class="open ? 'border-t border-hairline' : 'hidden lg:block'">
      <div v-for="(section, index) in sections" :key="section.heading">
        <p
          class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-fg-3"
          :class="index === 0 ? '' : 'pt-4'"
        >
          {{ section.heading }}
        </p>

        <ul>
          <li v-for="item in section.items" :key="item.key">
            <Link
              :href="item.url"
              class="relative flex items-center gap-2.5 rounded px-3 py-1.5 transition-colors"
              :class="item.key === active ? 'bg-state-live/10' : 'hover:bg-surface-2'"
              :aria-current="item.key === active ? 'page' : null"
            >
              <span
                v-if="item.key === active"
                class="absolute inset-y-1 left-0 w-0.5 rounded-r bg-state-live"
                aria-hidden="true"
              />

              <ManageIcon
                :name="item.icon"
                :size="16"
                class="shrink-0"
                :class="item.key === active ? 'text-state-live' : 'text-fg-3'"
              />

              <span
                class="min-w-0 flex-1 truncate text-[13px] font-medium"
                :class="item.key === active ? 'text-state-live' : 'text-fg-1'"
              >
                {{ item.label }}
              </span>
            </Link>
          </li>
        </ul>
      </div>

      <!-- Reset is one destructive button, not a pane to browse, so it is pinned under
           a divider rather than listed at the same weight as Chat. -->
      <div v-if="reset" class="mt-2 border-t border-hairline pt-2">
        <Link
          :href="reset.url"
          class="flex items-center gap-2.5 rounded px-3 py-1.5 text-[13px] font-medium text-state-danger transition-colors"
          :class="reset.key === active ? 'bg-state-danger/10' : 'hover:bg-state-danger/10'"
          :aria-current="reset.key === active ? 'page' : null"
        >
          {{ reset.label }}
        </Link>
      </div>
    </div>
  </nav>
</template>
