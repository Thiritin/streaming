<script setup>
/**
 * One cell of a manage table, by the column types documented on App\Support\Manage\Column.
 *
 * It is a component rather than markup inside DataTable because the same value is drawn
 * twice: as a row cell on a wide screen and as a field inside a card on a narrow one.
 * Only the envelope differs, so only the envelope lives in DataTable.
 */
import { router } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import StatusBadge from './StatusBadge.vue';
import { resolve, toneText } from './tones.js';

const props = defineProps({
  column: { type: Object, required: true },
  value: { type: [String, Number, Boolean, Object, Array], default: null },
  /** Cards give an image more room than a 24px row does. */
  imageClass: { type: String, default: 'h-6 w-10' },
});

const isEmpty = (value) => value === null || value === undefined || value === '';

const numberDisplay = (value) => {
  if (isEmpty(value)) {
    return null;
  }

  if (typeof value === 'object') {
    return value.display ?? null;
  }

  // A non-numeric value would format as the literal "NaN", which reads as broken
  // data rather than an empty cell, so it falls through to the placeholder.
  if (Number.isNaN(Number(value))) {
    return null;
  }

  return new Intl.NumberFormat('en-GB').format(value).replace(/,/g, ' ');
};

const copy = async (value) => {
  try {
    await navigator.clipboard.writeText(String(value));
  } catch {
    // Clipboard is unavailable outside a secure context; nothing to recover from.
  }
};

const toggle = () => router.post(props.value.url, {}, { preserveScroll: true });
</script>

<template>
  <StatusBadge v-if="column.type === 'badge'" :status="value" />

  <template v-else-if="column.type === 'number'">
    <span v-if="numberDisplay(value) !== null">
      {{ numberDisplay(value) }}
      <span v-if="value?.description" class="block text-[11px] text-fg-3">{{ value.description }}</span>
    </span>
    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
  </template>

  <template v-else-if="column.type === 'image'">
    <img
      v-if="!isEmpty(value)"
      :src="value"
      alt=""
      class="rounded-sm border border-hairline object-cover"
      :class="imageClass"
    />
    <span v-else class="text-fg-3">—</span>
  </template>

  <ManageIcon
    v-else-if="column.type === 'bool'"
    :name="value ? 'circle-check' : 'circle-x'"
    :size="15"
    :class="value ? 'inline text-state-ok' : 'inline text-fg-3'"
  />

  <ManageIcon
    v-else-if="column.type === 'icon'"
    :name="value?.icon"
    :size="15"
    class="inline"
    :class="resolve(toneText, value?.tone)"
    :title="value?.title"
  />

  <span v-else-if="column.type === 'color'" class="inline-flex items-center gap-1.5">
    <span class="inline-block size-3.5 rounded-sm border border-hairline" :style="{ backgroundColor: value }" />
    <span class="font-mono text-[12px] text-fg-2">{{ value }}</span>
  </span>

  <template v-else-if="column.type === 'copyable'">
    <button
      v-if="!isEmpty(value)"
      type="button"
      class="group inline-flex items-center gap-1 font-mono text-[12px] text-fg-1"
      :title="`Copy ${value}`"
      @click.stop="copy(value)"
    >
      {{ value }}
      <ManageIcon name="copy" :size="12" class="opacity-0 transition-opacity group-hover:opacity-60" />
    </button>
    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
  </template>

  <input
    v-else-if="column.type === 'toggle'"
    type="checkbox"
    class="accent-state-live"
    :checked="value?.value"
    :aria-label="column.label"
    @click.stop
    @change="toggle"
  />

  <template v-else-if="column.type === 'datetime'">
    <span v-if="!isEmpty(value)" class="tabular-nums" :title="value?.title">
      {{ value?.display ?? value }}
    </span>
    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
  </template>

  <template v-else>
    <span v-if="!isEmpty(value)">{{ value }}</span>
    <span v-else class="text-fg-3">{{ column.fallback ?? '—' }}</span>
  </template>
</template>
