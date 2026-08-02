<script setup>
/**
 * A titled block of fields, the equivalent of Filament's Section.
 *
 * One column of label/control rows by default (see FormField): the form is read top to
 * bottom rather than scanned in a grid. `columns` is kept for the few blocks that are
 * genuinely a row of short read-only values, e.g. statistics.
 */
import { ref } from 'vue';
import ManageIcon from './ManageIcon.vue';

const props = defineProps({
  title: { type: String, required: true },
  description: { type: String, default: null },
  columns: { type: Number, default: 1 },
  collapsible: { type: Boolean, default: false },
  collapsed: { type: Boolean, default: false },
});

const open = ref(!props.collapsed);

const gridClass = {
  1: 'md:grid-cols-1',
  2: 'md:grid-cols-2',
  3: 'md:grid-cols-3',
};
</script>

<template>
  <section class="rounded border border-hairline bg-surface-1">
    <header
      class="flex items-center gap-2 border-b border-hairline px-3 py-2"
      :class="collapsible ? 'cursor-pointer select-none' : ''"
      @click="collapsible && (open = !open)"
    >
      <div class="min-w-0">
        <h2 class="text-[12px] font-semibold uppercase tracking-wide text-fg-1">{{ title }}</h2>
        <p v-if="description" class="text-[11px] text-fg-3">{{ description }}</p>
      </div>
      <ManageIcon
        v-if="collapsible"
        :name="open ? 'chevron-up' : 'chevron-down'"
        class="ml-auto text-fg-3"
      />
    </header>

    <div
      v-if="open"
      class="px-3 py-2"
      :class="columns > 1 ? ['grid grid-cols-1 gap-x-6 gap-y-1', gridClass[columns]] : 'divide-y divide-hairline/40'"
    >
      <slot />
    </div>
  </section>
</template>
