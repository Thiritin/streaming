<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import UserAvatar from '@/Components/UserAvatar.vue';

/**
 * The box itself. Owns no state beyond whether it is open: the form comes from
 * whoever is posting it, so the top-level composer and every reply box share one
 * shape and one error slot.
 *
 * Closed it is a line, not a form - no border box, no buttons, no counter waiting
 * to be filled in. A comment section that greets a reader with a bordered panel
 * and a disabled button reads as paperwork; a line with a cursor in it reads as
 * somewhere to say something. It grows into the full thing on the first click,
 * which is the moment somebody has decided to write.
 */
const props = defineProps({
  form: { type: Object, required: true },
  placeholder: { type: String, default: 'Say something' },
  submitLabel: { type: String, default: 'Comment' },
  compact: { type: Boolean, default: false },
  autofocus: { type: Boolean, default: false },
  maxLength: { type: Number, default: 1500 },
  /** The person writing, when there is a picture worth putting beside the line. */
  author: { type: Object, default: null },
});

const emit = defineEmits(['submit', 'cancel']);

const field = ref(null);
const open = ref(props.autofocus);

onMounted(() => {
  if (props.autofocus) nextTick(() => field.value?.focus());
});

// Reopened by whatever put text back into it - a failed post keeps what was typed.
watch(
  () => props.form.body,
  (value) => {
    if (value) open.value = true;
  }
);

const remaining = computed(() => props.maxLength - (props.form.body?.length ?? 0));
const tooLong = computed(() => remaining.value < 0);
const empty = computed(() => !props.form.body?.trim());

const expand = () => {
  open.value = true;
  nextTick(() => field.value?.focus());
};

// Closes again only if nothing was typed: a half-written comment must not vanish
// because the cursor went somewhere else.
const onBlur = () => {
  if (empty.value && !props.compact) open.value = false;
};

const cancel = () => {
  props.form.reset('body');
  open.value = false;
  emit('cancel');
};

// Enter sends, shift+enter breaks the line. A comment is usually one line, and
// reaching for the button for every one of them is the slower half of posting.
const onKeydown = (event) => {
  if (event.key === 'Enter' && !event.shiftKey && !empty.value && !tooLong.value) {
    event.preventDefault();
    emit('submit');
  }
};
</script>

<template>
  <form class="flex gap-3" @submit.prevent="emit('submit')">
    <UserAvatar
      v-if="author && !compact"
      :name="author.name"
      :src="author.avatar"
      size="size-9"
    />

    <div class="min-w-0 flex-1">
      <textarea
        ref="field"
        v-model="form.body"
        class="comment-input"
        :class="{ 'is-open': open }"
        :rows="open ? (compact ? 2 : 3) : 1"
        :placeholder="placeholder"
        @focus="open = true"
        @click="expand"
        @blur="onBlur"
        @keydown="onKeydown"
      />

      <p v-if="form.errors.body" class="mt-1 text-xs text-red-400">{{ form.errors.body }}</p>

      <div v-if="open" class="mt-2 flex items-center justify-end gap-3">
        <!-- Only once it is close: a counter sitting under an empty box is noise. -->
        <span v-if="remaining < 200" class="text-xs tabular-nums" :class="tooLong ? 'text-red-400' : 'text-primary-400'">
          {{ remaining }}
        </span>

        <button type="button" class="comment-cancel" @mousedown.prevent @click="cancel">Cancel</button>

        <button type="submit" class="comment-submit" :disabled="empty || tooLong || form.processing">
          {{ form.processing ? 'Posting...' : submitLabel }}
        </button>
      </div>
    </div>
  </form>
</template>

<style scoped>
@reference "../../../css/app.css";

/* Closed: a hairline under a line of text. Open: the box it always was. */
.comment-input {
  @apply w-full resize-none border-0 border-b border-white/10 bg-transparent px-0 py-1.5 text-sm text-white placeholder:text-primary-400;
  transition: border-color 150ms ease;
}

.comment-input:hover:not(.is-open) {
  @apply border-white/25;
}

.comment-input:focus {
  @apply border-primary-400 outline-none ring-0;
}

.comment-input.is-open {
  @apply resize-y rounded-lg border border-white/10 bg-primary-950/40 px-3 py-2;
}

.comment-submit {
  @apply rounded-full bg-primary-500 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400;
}

.comment-submit:disabled {
  @apply cursor-not-allowed bg-white/5 text-primary-400 hover:bg-white/5;
}

.comment-cancel {
  @apply text-sm font-semibold text-primary-400 transition-colors hover:text-white;
}
</style>
