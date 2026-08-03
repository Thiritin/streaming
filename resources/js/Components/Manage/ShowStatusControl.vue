<script setup>
/**
 * The show's status, and the buttons that change it, in one row.
 *
 * There is no status dropdown. Every transition does more than write a column - Go Live
 * stamps `actual_start` and notifies viewers, End Stream closes the recording out-point,
 * Cancel keeps the slot on the schedule marked cancelled - so picking a value from a select
 * could only ever be a lie. The server decides which buttons exist; this renders them next
 * to the state they act on.
 */
import ActionButton from './ActionButton.vue';
import StatusBadge from './StatusBadge.vue';

defineProps({
  /** Status::make() triple from the server */
  status: { type: Object, default: null },
  /** Only the transition actions: go_live, end_stream, cancel */
  actions: { type: Array, default: () => [] },
});
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <StatusBadge :status="status" />

    <ActionButton v-for="action in actions" :key="action.name" :action="action" />

    <span v-if="!actions.length" class="text-[11px] text-fg-3">
      Nothing left to do from here.
    </span>
  </div>
</template>
