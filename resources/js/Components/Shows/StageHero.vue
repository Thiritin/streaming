<template>
  <section class="stage-hero">
    <!-- Backdrop is pure CSS on purpose: a blurred full-bleed <img> costs a filter
         pass on every scrolled frame, and a gradient reads the same behind a panel. -->
    <div class="absolute inset-0 stage-backdrop" aria-hidden="true" />

    <div class="relative mx-auto max-w-page px-4 sm:px-6 lg:px-8 pt-6 pb-8">
      <!-- The player is capped by width, not height. Capping the height of a 16:9
           box makes the ratio transfer back into the width, so the box narrows while
           the track it sits in stays 1fr: the ring and shadow kept the full column
           and left a band of empty frame beside the picture - 264px at 1680x900,
           484px at 1680x700, and only 24px at 1440x900, which is why it read as fine.
           62vh of height is 62 * 16/9 = 110.22vh of width, so capping the track and
           the panel at that gives the same ceiling with nothing left over. -->
      <!-- With side channels the tracks are 2fr/1fr, because that is what makes two
           stacked 16:9 boxes come out the height of one twice their width; the row is
           then capped at 1.5x the same ceiling so the featured track keeps it. -->
      <div
        class="grid gap-4 lg:gap-5"
        :class="hasSideShows
          ? 'lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)] lg:max-w-[165.33vh]'
          : 'lg:grid-cols-[minmax(0,110.22vh)_minmax(320px,1fr)]'"
      >
        <!-- Featured channel. The whole frame is a link into the show page: playing
             it inline with no controls is what had people stuck on the front page
             looking for a scrubber, a quality picker and chat. -->
        <LivePreview
          :show="show"
          variant="featured"
          :with-text="hasSideShows"
          :with-mute="true"
          :priority="true"
          :source-status="sourceStatus"
          :class="hasSideShows ? null : 'max-w-[110.22vh]'"
        />

        <!-- Right column. When more than one channel is on, it carries the others
             so the page says what is happening everywhere at a glance; otherwise it
             is the featured show's own text. -->
        <div v-if="hasSideShows" class="flex flex-col gap-4">
          <LivePreview
            v-for="side in sideShows"
            :key="side.id"
            :show="side"
            variant="compact"
            :with-text="true"
            :low-quality="true"
          />

          <ChatExcerpt
            v-if="chat?.source_id && sideShows.length < 2"
            :chat="chat"
            :show-slug="show.slug"
            class="min-h-0 flex-1"
          />
        </div>

        <!-- Show text sits directly on the page: title and description, nothing else.
             Channel, viewers and runtime are already on the player overlay.

             The inner block is absolutely positioned from lg up so this column has
             no intrinsic height: the player alone decides how tall the row is, and
             a long chat scrolls inside instead of stretching the hero. -->
        <div v-else class="relative">
          <div class="stage-copy lg:absolute lg:inset-0">
            <h1 class="text-2xl lg:text-[28px] font-bold text-white leading-tight tracking-tight text-balance">
              {{ show.title }}
            </h1>

            <MarkdownText
              v-if="show.description"
              :html="show.description_html"
              :text="show.description"
              class="text-sm leading-relaxed text-primary-200/80"
            />

            <ChatExcerpt v-if="chat?.source_id" :chat="chat" :show-slug="show.slug" />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import LivePreview from './LivePreview.vue';
import ChatExcerpt from './ChatExcerpt.vue';
import MarkdownText from '@/Components/MarkdownText.vue';

const props = defineProps({
  show: {
    type: Object,
    required: true,
  },
  chat: {
    type: Object,
    default: null,
  },
  // Status of the channel behind the show, when the page tracks it. A show can be
  // live while its source is not publishing, and that is not "connecting".
  sourceStatus: {
    type: String,
    default: null,
  },
  // The other channels that are on right now. Two at most: a third row would be
  // shorter than the copy it carries, and the grid below already lists everything.
  sideShows: {
    type: Array,
    default: () => [],
  },
});

const hasSideShows = computed(() => props.sideShows.length > 0);
</script>

<style scoped>
@reference "../../../css/app.css";

.stage-hero {
  @apply relative isolate;
}

.stage-backdrop {
  background:
    radial-gradient(120% 80% at 20% 0%, color-mix(in oklab, var(--color-primary-700) 55%, transparent) 0%, transparent 60%),
    linear-gradient(to bottom, var(--color-primary-950) 0%, var(--color-primary-900) 100%);
}

.stage-copy {
  @apply flex min-h-0 flex-col gap-3 pt-1 lg:pt-2;
}
</style>
