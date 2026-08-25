<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import CommentItem from '@/Components/Recordings/CommentItem.vue';
import CommentComposer from '@/Components/Recordings/CommentComposer.vue';

const props = defineProps({
  recordingId: { type: [Number, String], required: true },
  comments: { type: Array, default: () => [] },
  // How much of the thread is on screen, and whether there is more behind it.
  meta: {
    type: Object,
    default: () => ({ shown: 0, total: 0, hasMore: false, pageSize: 20 }),
  },
  // False for a guest: a comment is attributed, so there is nothing to post as.
  canComment: { type: Boolean, default: false },
  loginUrl: { type: String, default: null },
});

const page = usePage();

// Whose picture sits beside the line. Only the top-level box gets one: a reply
// box is already indented under an avatar.
const viewer = computed(() => page.props.auth?.user ?? null);

const form = useForm({ body: '', parent_id: null });

const loadingMore = ref(false);

/*
 * Load more widens the window rather than asking for page two: posting and
 * hearting both re-render the page, and a window is the only shape that survives
 * that without the comments a viewer had already opened folding back up.
 */
const loadMore = () => {
  loadingMore.value = true;

  router.reload({
    only: ['comments', 'commentsMeta'],
    data: { comments: (props.meta.shown ?? 0) + (props.meta.pageSize ?? 20) },
    preserveScroll: true,
    onFinish: () => (loadingMore.value = false),
  });
};

// Replies count too. The heading answers "how much is said here", and a thread of
// one comment and nine replies is not one comment.
const total = computed(
  () =>
    (props.meta.total ?? props.comments.length)
    + props.comments.reduce((sum, comment) => sum + (comment.replies?.length ?? 0), 0)
);

const submit = () => {
  form.post(route('recordings.comments.store', props.recordingId), {
    preserveScroll: true,
    onSuccess: () => form.reset('body'),
  });
};
</script>

<template>
  <section class="mt-8">
    <h2 class="text-base font-semibold text-white">
      {{ total }} {{ total === 1 ? 'comment' : 'comments' }}
    </h2>

    <div v-if="canComment" class="mt-4">
      <CommentComposer :form="form" :author="viewer" @submit="submit" />
    </div>

    <p v-else class="mt-4 rounded-lg border border-white/10 bg-primary-950/40 px-4 py-3 text-sm text-primary-300">
      <Link v-if="loginUrl" :href="loginUrl" class="font-semibold text-white hover:underline">Sign in</Link>
      <span v-else class="font-semibold text-white">Sign in</span>
      to join the conversation.
    </p>

    <!-- Its own scroll area: a long thread should not push the page's own end
         hundreds of comments down, and the box above stays reachable while it is
         read. It only grows a scrollbar once there is more than a screenful. -->
    <div v-if="comments.length" class="comment-scroll mt-8">
      <!-- Keyed by id, so a comment that arrives at the top of the thread slides
           in and the ones under it move down rather than the whole list redrawing
           in place. Reduced motion turns all of it off. -->
      <TransitionGroup name="comment" tag="div" class="space-y-6">
        <CommentItem
          v-for="comment in comments"
          :key="comment.id"
          :comment="comment"
          :recording-id="recordingId"
          :can-comment="canComment"
        />
      </TransitionGroup>

      <div v-if="meta.hasMore" class="pt-6 text-center">
        <button type="button" class="load-more" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Loading...' : 'Load more comments' }}
        </button>
      </div>
    </div>

    <p v-else class="mt-6 text-sm text-primary-400">Nothing here yet. Say the first thing.</p>
  </section>
</template>

<style scoped>
@reference "../../../css/app.css";

.comment-enter-active,
.comment-leave-active {
  transition: opacity 220ms ease, transform 220ms ease;
}

.comment-enter-from {
  opacity: 0;
  transform: translateY(-8px);
}

.comment-leave-to {
  opacity: 0;
  transform: translateY(4px);
}

/* Everything still on screen slides into its new place rather than jumping. */
.comment-move {
  transition: transform 260ms ease;
}

.comment-leave-active {
  position: absolute;
}

@media (prefers-reduced-motion: reduce) {
  .comment-enter-active,
  .comment-leave-active,
  .comment-move {
    transition: none;
  }
}

.comment-scroll {
  @apply max-h-[70vh] overflow-y-auto pr-1;
  scrollbar-width: thin;
}

.load-more {
  @apply rounded-lg border border-white/10 px-4 py-2 text-sm font-semibold text-primary-200 transition-colors hover:border-white/25 hover:text-white;
}

.load-more:disabled {
  @apply cursor-not-allowed text-primary-400;
}
</style>
