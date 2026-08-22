<script setup>
/**
 * The show's status, and the buttons that change it, in one row.
 *
 * The transitions are not a dropdown. Every one of them does more than write a column -
 * Go Live stamps `actual_start` and notifies viewers, End Stream closes the recording
 * out-point, Cancel keeps the slot on the schedule marked cancelled - so picking a value
 * from a select could only ever be a lie. The server decides which buttons exist; this
 * renders them next to the state they act on.
 *
 * The pen beside the badge is the exception, and only that: it writes the status by hand,
 * side effects and all, which is the way back out of a status set in error.
 */
import { computed } from 'vue';
import ActionButton from './ActionButton.vue';
import StatusBadge from './StatusBadge.vue';

const props = defineProps({
  /** Status::make() triple from the server */
  status: { type: Object, default: null },
  /** The transition actions plus set_status: go_live, end_stream, cancel, set_status */
  actions: { type: Array, default: () => [] },
});

const setStatus = computed(() => props.actions.find((action) => action.name === 'set_status') ?? null);
const transitions = computed(() => props.actions.filter((action) => action.name !== 'set_status'));
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <StatusBadge :status="status" />

    <ActionButton v-if="setStatus" :action="setStatus" icon-only />

    <ActionButton v-for="action in transitions" :key="action.name" :action="action" />

    <span v-if="!transitions.length" class="text-[11px] text-fg-3">
      Nothing left to do from here.
    </span>
  </div>
</template>
