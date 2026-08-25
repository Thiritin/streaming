<script setup>
/**
 * The operator's panel on the watch page.
 *
 * Everything in here is for somebody who can change the recording, and the server
 * sends the whole prop only to them - a viewer's browser never receives it, so
 * this is not a hidden button somebody can find in the markup.
 *
 * It exists because the person who notices an intermission is the one watching the
 * recording, not the one looking at a form. Marking it here means the playhead they
 * are already parked on is the marker, rather than finding the moment a second time
 * in /manage.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import SkipEditor from '@/Components/Recordings/SkipEditor.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';

const props = defineProps({
  tools: { type: Object, required: true },
  /** What the recording currently carries, straight from the page's props. */
  skips: { type: Array, default: () => [] },
  /** Where the player is, so in and out mark against what is on screen. */
  currentTime: { type: Number, default: 0 },
});

const emit = defineEmits(['seek']);

const open = ref(false);
const saving = ref(false);
const draft = ref(clone(props.skips));

function clone(segments) {
  return (segments ?? []).map((segment) => ({ ...segment }));
}

// The page re-renders after a save and after anything else that visits it. What
// the server now holds becomes the draft again, unless there are unsaved changes
// in front of somebody - those are theirs to keep or discard.
watch(
  () => props.skips,
  (segments) => {
    if (!dirty.value) draft.value = clone(segments);
  }
);

const dirty = computed(() => JSON.stringify(draft.value) !== JSON.stringify(props.skips));

const save = () => {
  saving.value = true;

  router.patch(
    props.tools.skipsUrl,
    { skip_segments: draft.value },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => (saving.value = false),
    }
  );
};

const revert = () => (draft.value = clone(props.skips));
</script>

<template>
  <section class="tools">
    <button type="button" class="tools-head" @click="open = !open">
      <span class="flex items-center gap-2">
        <ManageIcon name="scissors" />
        Tools
        <span v-if="dirty" class="tools-dirty">unsaved</span>
      </span>
      <ManageIcon :name="open ? 'chevron-up' : 'chevron-down'" />
    </button>

    <div v-if="open" class="tools-body">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="text-sm font-semibold text-white">Skip points</h3>
        <a :href="tools.manageUrl" class="text-xs font-semibold text-primary-400 hover:text-white">
          Open in panel
        </a>
      </div>

      <p class="text-xs text-primary-400">
        Park the playhead and press in, park it again and press out. Viewers are offered a
        button while they are inside one; nothing is cut.
      </p>

      <!-- The keys only bind while the panel is open, or I and O would be taken
           away from a page somebody is only watching. -->
      <SkipEditor
        v-model="draft"
        :duration="tools.duration"
        :current-time="currentTime"
        :keyboard="open"
        @seek="emit('seek', $event)"
      />

      <div class="flex items-center justify-end gap-3">
        <button v-if="dirty" type="button" class="tools-revert" @click="revert">Revert</button>
        <button type="button" class="tools-save" :disabled="!dirty || saving" @click="save">
          {{ saving ? 'Saving...' : 'Save skip points' }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
@reference "../../../css/app.css";

.tools {
  @apply mt-6 overflow-hidden rounded-xl border border-white/10 bg-primary-950/40;
}

.tools-head {
  @apply flex w-full items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-primary-200 transition-colors hover:text-white;
}

.tools-dirty {
  @apply rounded bg-amber-500/15 px-2 py-0.5 text-[11px] font-semibold text-amber-300;
}

.tools-body {
  @apply flex flex-col gap-3 border-t border-white/10 px-4 py-4;
}

.tools-save {
  @apply rounded-lg bg-primary-500 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}

.tools-save:disabled {
  @apply cursor-not-allowed bg-white/5 text-primary-400 hover:bg-white/5;
}

.tools-revert {
  @apply text-sm font-semibold text-primary-400 transition-colors hover:text-white;
}
</style>
