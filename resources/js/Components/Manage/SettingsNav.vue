<script setup>
/**
 * The settings menu, shared by the generated panes and by Categories, which is a
 * settings area with rows instead of knobs.
 *
 * Below lg it is a strip of chips that scrolls sideways: a 256px column beside the
 * form leaves nothing for the form.
 */
import { Link } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';

defineProps({
  navigation: { type: Array, default: () => [] },
  active: { type: String, required: true },
});
</script>

<template>
  <nav
    class="shrink-0 border-b border-hairline lg:w-64 lg:border-r lg:border-b-0"
    aria-label="Settings sections"
  >
    <div class="sticky top-0 flex gap-1 overflow-x-auto p-2 [scrollbar-width:none] lg:block lg:space-y-1 lg:overflow-visible [&::-webkit-scrollbar]:hidden">
      <Link
        v-for="item in navigation"
        :key="item.key"
        :href="item.url"
        class="relative flex shrink-0 items-center gap-2.5 overflow-hidden rounded border border-hairline px-3 py-2 transition-colors lg:items-start lg:border-0 lg:py-2.5"
        :class="item.key === active ? 'bg-state-live/10' : 'hover:bg-surface-2'"
        :aria-current="item.key === active ? 'page' : null"
      >
        <span
          v-if="item.key === active"
          class="absolute top-1 bottom-1 left-0 hidden w-0.5 rounded-r bg-state-live lg:block"
          aria-hidden="true"
        />

        <ManageIcon
          :name="item.icon"
          :size="16"
          class="mt-px shrink-0"
          :class="item.key === active ? 'text-state-live' : 'text-fg-3'"
        />

        <span class="min-w-0 flex-1">
          <span
            class="block whitespace-nowrap text-[13px] font-medium lg:truncate"
            :class="item.key === active ? 'text-state-live' : 'text-fg-1'"
          >
            {{ item.label }}
          </span>
          <span class="mt-0.5 hidden text-[11px] leading-[15px] text-fg-3 lg:block">
            {{ item.blurb }}
          </span>
        </span>
      </Link>
    </div>
  </nav>
</template>
