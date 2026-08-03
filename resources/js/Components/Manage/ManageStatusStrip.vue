<script setup>
/**
 * The numbers an operator must be able to see without navigating: stream state, live
 * shows, edge capacity, viewers.
 *
 * Polls on its own interval and reloads only its own prop, so it keeps ticking while a
 * list page is being filtered or a form is being filled in.
 */
import { computed } from 'vue';
import { Link, usePoll } from '@inertiajs/vue3';
import ManageIcon from './ManageIcon.vue';
import { resolve, toneDot, toneText } from './tones.js';

const props = defineProps({
  status: { type: Object, default: null },
  brand: { type: String, default: 'Streaming' },
  user: { type: Object, default: null },
});

usePoll(10000, { only: ['manageStatus'] });

const edgeTone = computed(() => {
  const { active = 0, total = 0 } = props.status?.edge ?? {};

  if (total === 0) {
    return 'idle';
  }

  return active === total ? 'ok' : 'warn';
});

const viewers = computed(() =>
  new Intl.NumberFormat('en-GB').format(props.status?.viewers ?? 0).replace(/,/g, ' '),
);

const segment = 'flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide';
</script>

<template>
  <header
    class="flex h-10 shrink-0 items-center gap-4 border-b border-hairline bg-surface-1 px-4"
    aria-label="Stream status"
  >
    <span class="text-[12px] font-semibold text-fg-1">{{ brand }}</span>

    <template v-if="status">
      <span :class="[segment, resolve(toneText, status.stream.tone)]">
        <span class="size-1.5 rounded-full" :class="resolve(toneDot, status.stream.tone)" />
        {{ status.stream.label }}
      </span>

      <Link
        v-if="route().has('manage.shows.index')"
        :href="route('manage.shows.index')"
        :class="[segment, 'text-fg-2 transition-colors hover:text-fg-1']"
      >
        <ManageIcon name="play-circle" :size="13" />
        <span class="tabular-nums">{{ status.liveShows }}</span> live
      </Link>
      <span v-else :class="[segment, 'text-fg-2']">
        <ManageIcon name="play-circle" :size="13" />
        <span class="tabular-nums">{{ status.liveShows }}</span> live
      </span>

      <Link
        v-if="route().has('manage.servers.index')"
        :href="route('manage.servers.index')"
        :class="[segment, resolve(toneText, edgeTone), 'transition-opacity hover:opacity-80']"
      >
        <ManageIcon name="server" :size="13" />
        <span class="tabular-nums">{{ status.edge.active }}/{{ status.edge.total }}</span> edge
      </Link>
      <span v-else :class="[segment, resolve(toneText, edgeTone)]">
        <ManageIcon name="server" :size="13" />
        <span class="tabular-nums">{{ status.edge.active }}/{{ status.edge.total }}</span> edge
      </span>

      <span :class="[segment, 'text-fg-2']">
        <ManageIcon name="eye" :size="13" />
        <span class="tabular-nums">{{ viewers }}</span> viewers
      </span>
    </template>

    <div class="ml-auto flex items-center gap-3">
      <span v-if="user" class="text-[11px] text-fg-3">{{ user.name }}</span>
      <a
        :href="route('shows.grid')"
        class="text-fg-3 transition-colors hover:text-fg-1"
        title="Back to the public site"
      >
        <ManageIcon name="external-link" :size="14" />
      </a>
    </div>
  </header>
</template>
