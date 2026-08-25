<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import UserAvatar from '@/Components/UserAvatar.vue';
import CommentComposer from '@/Components/Recordings/CommentComposer.vue';
import { postedAgo } from '@/utils/postedAgo';

const props = defineProps({
  comment: { type: Object, required: true },
  recordingId: { type: [Number, String], required: true },
  // A reply has no reply button of its own: the thread is one level, and a reply
  // posted against a reply is filed under the same parent anyway.
  isReply: { type: Boolean, default: false },
  canComment: { type: Boolean, default: false },
});

const replying = ref(false);
const confirmingDelete = ref(false);
const reporting = ref(false);
const editing = ref(false);

const edit = useForm({ body: props.comment.body });

const submitEdit = () => {
  edit.patch(route('recordings.comments.update', [props.recordingId, props.comment.id]), {
    preserveScroll: true,
    onSuccess: () => (editing.value = false),
  });
};

// The row is redrawn from props after every visit, so an edit box left open
// against the previous text would save the wrong thing.
watch(
  () => props.comment.body,
  (body) => (edit.body = body)
);

const report = useForm({ message: '' });

const submitReport = () => {
  report.post(route('recordings.comments.report', [props.recordingId, props.comment.id]), {
    preserveScroll: true,
    onSuccess: () => {
      report.reset('message');
      reporting.value = false;
    },
  });
};

// Moderators only, and only on something already hidden. The button is here as
// well as in /manage because a comment reported out of spite is quickest undone
// by whoever is already reading the thread.
const approve = () => {
  router.post(route('recordings.comments.approve', [props.recordingId, props.comment.id]), {}, {
    preserveScroll: true,
  });
};

const reply = useForm({ body: '', parent_id: props.comment.id });

const submitReply = () => {
  reply.post(route('recordings.comments.store', props.recordingId), {
    preserveScroll: true,
    onSuccess: () => {
      reply.reset('body');
      replying.value = false;
    },
  });
};

// Two presses rather than a browser confirm box: deleting a comment is small
// enough that a modal is heavier than the action, and it takes its replies with
// it, so it still should not go on one stray click.
// The count comes back with the page; the press only asks for the toggle. No
// optimistic bump: a heart that appears and then vanishes when the reply lands
// reads as a bug, and this is one small visit.
const toggleHeart = () => {
  router.post(route('recordings.comments.heart', [props.recordingId, props.comment.id]), {}, {
    preserveScroll: true,
    preserveState: true,
  });
};

const remove = () => {
  router.delete(route('recordings.comments.destroy', [props.recordingId, props.comment.id]), {
    preserveScroll: true,
    onFinish: () => (confirmingDelete.value = false),
  });
};
</script>

<template>
  <article class="flex gap-3" :class="isReply ? 'pl-4 sm:pl-6' : ''">
    <UserAvatar
      :name="comment.author.name"
      :src="comment.author.avatar"
      :size="isReply ? 'size-7' : 'size-9'"
    />

    <div class="min-w-0 flex-1">
      <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
        <span class="font-semibold text-white">{{ comment.author.name }}</span>
        <span class="text-xs text-primary-400">
          {{ postedAgo(comment.created_at) }}<template v-if="comment.edited"> (edited)</template>
        </span>
      </p>

      <!-- The room does not get this row at all. Its author is told why it is
           only theirs, and a moderator is told what was said about it. -->
      <p v-if="comment.hidden" class="hidden-note">
        <template v-if="comment.hidden_for === 'moderator'">
          Hidden by {{ comment.report_count }} {{ comment.report_count === 1 ? 'report' : 'reports' }}
        </template>
        <template v-else>Hidden while a moderator looks at it. Only you can see it.</template>
      </p>

      <CommentComposer
        v-if="editing"
        class="mt-2"
        :form="edit"
        placeholder="Say something"
        submit-label="Save"
        compact
        autofocus
        @submit="submitEdit"
        @cancel="editing = false"
      />

      <p v-else class="comment-body" :class="{ 'is-hidden': comment.hidden }">{{ comment.body }}</p>

      <ul v-if="comment.reports.length" class="report-list">
        <li v-for="entry in comment.reports" :key="entry.id">
          <span class="font-semibold text-primary-200">{{ entry.by }}:</span> {{ entry.message }}
        </li>
      </ul>

      <div class="mt-1 flex items-center gap-3 text-xs">
        <button
          type="button"
          class="heart"
          :class="{ 'is-hearted': comment.hearted }"
          :disabled="!canComment"
          :aria-pressed="comment.hearted"
          :title="canComment ? (comment.hearted ? 'Remove your heart' : 'Heart this') : 'Sign in to heart this'"
          @click="toggleHeart"
        >
          <svg class="size-3.5" viewBox="0 0 24 24" :fill="comment.hearted ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.5S3.5 14.9 3.5 9.4A4.4 4.4 0 0 1 12 7.3a4.4 4.4 0 0 1 8.5 2.1c0 5.5-8.5 11.1-8.5 11.1z" />
          </svg>
          <span v-if="comment.hearts" class="tabular-nums">{{ comment.hearts }}</span>
        </button>

        <button
          v-if="!isReply && canComment"
          type="button"
          class="comment-action"
          @click="replying = !replying"
        >
          Reply
        </button>

        <button
          v-if="comment.can_report && !reporting"
          type="button"
          class="comment-action"
          @click="reporting = true"
        >
          Report
        </button>

        <button v-if="comment.can_approve" type="button" class="comment-action text-green-400 hover:text-green-300" @click="approve">
          Approve
        </button>

        <button
          v-if="comment.can_edit && !editing"
          type="button"
          class="comment-action"
          @click="editing = true"
        >
          Edit
        </button>

        <template v-if="comment.can_delete">
          <button
            v-if="!confirmingDelete"
            type="button"
            class="comment-action"
            @click="confirmingDelete = true"
          >
            Delete
          </button>
          <template v-else>
            <button type="button" class="comment-action text-red-400 hover:text-red-300" @click="remove">
              Delete{{ comment.replies?.length ? ' with replies' : '' }}?
            </button>
            <button type="button" class="comment-action" @click="confirmingDelete = false">Cancel</button>
          </template>
        </template>
      </div>

      <!-- Deliberately a line of text and not a menu of reasons: a moderator
           reading "same copypasta as the other four" acts faster than one reading
           "Other". -->
      <form v-if="reporting" class="report-form" @submit.prevent="submitReport">
        <label class="sr-only" :for="`report-${comment.id}`">Why are you reporting this?</label>
        <input
          :id="`report-${comment.id}`"
          v-model="report.message"
          type="text"
          maxlength="500"
          placeholder="What is wrong with it?"
          class="report-input"
        />
        <div class="flex items-center gap-3">
          <button type="submit" class="report-send" :disabled="!report.message.trim() || report.processing">
            {{ report.processing ? 'Sending...' : 'Send report' }}
          </button>
          <button type="button" class="comment-action" @click="reporting = false; report.reset('message')">Cancel</button>
        </div>
        <p v-if="report.errors.message" class="text-xs text-red-400">{{ report.errors.message }}</p>
        <p class="text-xs text-primary-400">Reporting hides it for everyone else until a moderator has looked.</p>
      </form>

      <CommentComposer
        v-if="replying"
        class="mt-3"
        :form="reply"
        :placeholder="`Reply to ${comment.author.name}`"
        submit-label="Reply"
        compact
        autofocus
        @submit="submitReply"
        @cancel="replying = false"
      />

      <TransitionGroup
        v-if="comment.replies?.length"
        name="reply"
        tag="div"
        class="mt-4 space-y-4 border-l border-white/10 pl-3 sm:pl-4"
      >
        <CommentItem
          v-for="child in comment.replies"
          :key="child.id"
          :comment="child"
          :recording-id="recordingId"
          :can-comment="canComment"
          is-reply
        />
      </TransitionGroup>
    </div>
  </article>
</template>

<style scoped>
@reference "../../../css/app.css";

.comment-body {
  @apply mt-0.5 whitespace-pre-line break-words text-sm text-primary-100;
}

.comment-action {
  @apply font-semibold text-primary-400 transition-colors hover:text-white;
}

.reply-enter-active,
.reply-leave-active {
  transition: opacity 200ms ease, transform 200ms ease;
}

.reply-enter-from,
.reply-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

.reply-move {
  transition: transform 240ms ease;
}

@media (prefers-reduced-motion: reduce) {
  .reply-enter-active,
  .reply-leave-active,
  .reply-move {
    transition: none;
  }
}

.hidden-note {
  @apply mt-1 inline-flex rounded bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-300;
}

.comment-body.is-hidden {
  @apply text-primary-300 italic;
}

.report-list {
  @apply mt-2 space-y-1 border-l-2 border-amber-500/40 pl-3 text-xs text-primary-300;
}

.report-form {
  @apply mt-3 space-y-2;
}

.report-input {
  @apply w-full rounded-lg border border-white/10 bg-primary-950/60 px-3 py-2 text-sm text-white placeholder:text-primary-400;
}

.report-input:focus {
  @apply border-primary-400 outline-none ring-2 ring-primary-500/30;
}

.report-send {
  @apply rounded-lg bg-amber-500/90 px-3 py-1.5 text-xs font-semibold text-primary-950 transition-colors hover:bg-amber-400;
}

.report-send:disabled {
  @apply cursor-not-allowed bg-primary-800 text-primary-400;
}

.heart {
  @apply inline-flex items-center gap-1 font-semibold text-primary-400 transition-colors;
}

.heart:hover:not(:disabled) {
  @apply text-red-300;
}

.heart:disabled {
  @apply cursor-default;
}

.heart.is-hearted {
  @apply text-red-400;
}
</style>
