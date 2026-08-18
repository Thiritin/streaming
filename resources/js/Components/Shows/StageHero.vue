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
      <div class="grid gap-4 lg:grid-cols-[minmax(0,110.22vh)_minmax(340px,1fr)] lg:gap-5">
        <!-- Player panel -->
        <div class="player-panel max-w-[110.22vh]">
          <div class="relative aspect-video bg-primary-950">
            <video
              v-show="streaming"
              ref="player"
              class="absolute inset-0 w-full h-full object-cover"
              :poster="show.thumbnail_url || undefined"
              muted
              playsinline
              autoplay
              @playing="onPlaying"
              @waiting="onWaiting"
            />

            <!-- Off air, or the stream has not attached yet -->
            <div v-if="!streaming" class="absolute inset-0">
              <!-- The hero still is the page's LCP element, so it loads eagerly
                   and at high priority; the fade is keyed on `load` so it eases
                   in rather than snapping over the placeholder. -->
              <img
                v-if="show.thumbnail_url"
                :src="show.thumbnail_url"
                :alt="show.title"
                loading="eager"
                fetchpriority="high"
                decoding="async"
                class="w-full h-full object-cover transition-[opacity,filter] duration-(--dur-slow) ease-(--ease-out-expo)"
                :class="heroImageLoaded ? 'opacity-100 blur-0' : 'opacity-0 blur-md'"
                @load="heroImageLoaded = true"
              />
              <TilePlaceholder v-else :label="show.source" />

              <div class="absolute inset-0 flex items-center justify-center bg-primary-950/55">
                <div class="text-center px-6">
                  <p class="text-sm font-semibold uppercase tracking-[0.14em] text-primary-200">
                    {{ playbackLabel }}
                  </p>
                  <p v-if="!isLive" class="mt-1 text-primary-300 text-sm">{{ startsLabel }}</p>
                </div>
              </div>
            </div>

            <!-- Status overlays -->
            <div class="absolute top-3 left-3 flex items-center gap-2">
              <span v-if="isLive" class="hero-badge-live">
                <span class="live-pip" aria-hidden="true" />
                Live
              </span>
              <span v-if="isLive && show.viewer_count" class="hero-badge">
                {{ formatViewerCount(show.viewer_count) }} watching
              </span>
            </div>

            <div v-if="isLive && show.started_at" class="absolute top-3 right-3">
              <span class="hero-badge tabular-nums">{{ liveDuration }}</span>
            </div>

            <!-- Sound + full player -->
            <div class="absolute bottom-3 left-3 right-3 flex items-center justify-between gap-3">
              <button
                v-if="streaming"
                type="button"
                class="hero-control"
                :aria-pressed="!muted"
                @click="toggleMuted"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11 5 6 9H3v6h3l5 4V5z" />
                  <path v-if="muted" stroke-linecap="round" d="m16 9 5 6m0-6-5 6" />
                  <path v-else stroke-linecap="round" d="M16 9a4 4 0 0 1 0 6m3-9a8 8 0 0 1 0 12" />
                </svg>
                {{ muted ? 'Unmute' : 'Mute' }}
              </button>
              <span v-else />

              <Link :href="route('show.view', show.slug)" class="hero-control">
                Open full player
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                </svg>
              </Link>
            </div>
          </div>
        </div>

        <!-- Show text sits directly on the page: title and description, nothing else.
             Channel, viewers and runtime are already on the player overlay.

             The inner block is absolutely positioned from lg up so this column has
             no intrinsic height: the player alone decides how tall the row is, and
             a long chat scrolls inside instead of stretching the hero. -->
        <div class="relative">
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
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch, nextTick } from 'vue';
import Hls from 'hls.js';
import TilePlaceholder from '../TilePlaceholder.vue';
import ChatExcerpt from './ChatExcerpt.vue';
import MarkdownText from '@/Components/MarkdownText.vue';
import { useNow } from '@/composables/useNow';

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
});

const now = useNow();
const player = ref(null);
const streaming = ref(false);
const failed = ref(false);
const muted = ref(true);
const heroImageLoaded = ref(false);
let hlsInstance = null;
let recoveries = 0;
const MAX_RECOVERIES = 3;

const isLive = computed(() => props.show.status === 'live');

// A source the page knows is down cannot be waited for. Only treat an unknown
// status as "still might arrive".
const sourceIsDown = computed(() => props.sourceStatus !== null && props.sourceStatus !== 'online');

// "Connecting" that never resolves is a lie; once the stream errors out, say so.
const playbackLabel = computed(() => {
  if (!isLive.value) return 'Off air';
  if (sourceIsDown.value) return props.sourceStatus === 'error' ? 'Connection interrupted' : 'Stream offline';

  return failed.value ? 'Stream unavailable' : 'Connecting';
});

const liveDuration = computed(() => {
  if (!props.show.started_at) return null;
  const diff = Math.max(0, Math.floor((now.value - new Date(props.show.started_at)) / 1000));
  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);
  const seconds = diff % 60;
  const pad = (n) => n.toString().padStart(2, '0');

  return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`;
});

const startsLabel = computed(() => {
  if (!props.show.scheduled_start) return 'Nothing scheduled';
  const start = new Date(props.show.scheduled_start);
  const diff = Math.floor((start - now.value) / 1000);

  if (diff <= 0) return 'Starting shortly';
  if (diff < 3600) return `in ${Math.ceil(diff / 60)}m`;
  if (diff < 86400) return `today at ${formatClock(props.show.scheduled_start)}`;

  return `${start.toLocaleDateString([], { weekday: 'short' })} at ${formatClock(props.show.scheduled_start)}`;
});

const formatClock = (value) => new Date(value).toLocaleTimeString([], {
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

const formatViewerCount = (count) => {
  if (count >= 1000000) return `${(count / 1000000).toFixed(1)}M`;
  if (count >= 1000) return `${(count / 1000).toFixed(1)}K`;
  return count?.toString() ?? '0';
};

const onPlaying = () => {
  streaming.value = true;
  failed.value = false;
};

// A stall is not a failure: HLS drops a segment now and then and recovers.
const onWaiting = () => {
  if (!hlsInstance) streaming.value = false;
};

const toggleMuted = () => {
  if (!player.value) return;
  muted.value = !muted.value;
  player.value.muted = muted.value;

  if (!muted.value) {
    player.value.play().catch(() => {
      // Some browsers refuse unmuted playback without a gesture; fall back to muted.
      muted.value = true;
      player.value.muted = true;
    });
  }
};

const stopPlayback = ({ errored = false } = {}) => {
  if (errored) failed.value = true;

  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }
  if (player.value) {
    player.value.pause();
    player.value.removeAttribute('src');
  }
  streaming.value = false;
};

// Autoplay is muted on purpose: browsers block audible autoplay, and a front page
// that shouts at you is worse than one you have to unmute.
const startPlayback = async () => {
  if (!props.show.hls_url || !isLive.value || sourceIsDown.value) return;

  await nextTick();
  const el = player.value;
  if (!el) return;

  el.muted = true;
  muted.value = true;
  failed.value = false;
  recoveries = 0;

  const fail = () => stopPlayback({ errored: true });

  if (Hls.isSupported()) {
    hlsInstance = new Hls({
      enableWorker: true,
      lowLatencyMode: false,
      maxBufferLength: 12,
      backBufferLength: 30,
    });
    hlsInstance.loadSource(props.show.hls_url);
    hlsInstance.attachMedia(el);
    hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
      el.play().catch(fail);
    });
    // Only fatal errors count. A 404 on one segment or a playlist refresh blip is
    // routine on a live edge, and hls.js already retries those itself.
    hlsInstance.on(Hls.Events.ERROR, (event, data) => {
      if (!data.fatal) return;

      if (data.type === Hls.ErrorTypes.NETWORK_ERROR && recoveries < MAX_RECOVERIES) {
        recoveries += 1;
        hlsInstance.startLoad();

        return;
      }

      if (data.type === Hls.ErrorTypes.MEDIA_ERROR && recoveries < MAX_RECOVERIES) {
        recoveries += 1;
        hlsInstance.recoverMediaError();

        return;
      }

      fail();
    });
  } else if (el.canPlayType('application/vnd.apple.mpegurl')) {
    el.src = props.show.hls_url;
    el.play().catch(fail);
  }
};

watch(() => [props.show.id, props.show.hls_url, props.show.status, props.sourceStatus], () => {
  stopPlayback();
  startPlayback();
});

onMounted(startPlayback);
onUnmounted(stopPlayback);
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

.player-panel {
  @apply overflow-hidden rounded-xl ring-1 ring-white/10 shadow-2xl shadow-primary-950/50;
}

.stage-copy {
  @apply flex min-h-0 flex-col gap-3 pt-1 lg:pt-2;
}

.hero-badge {
  @apply inline-flex items-center gap-1.5 rounded bg-black/70 px-2 py-1 text-[11px] font-medium text-white;
}

.hero-badge-live {
  @apply inline-flex items-center gap-1.5 rounded bg-red-600 px-2 py-1 text-[11px] font-bold uppercase tracking-wider text-white;
}

.hero-control {
  @apply inline-flex items-center gap-2 rounded-md bg-black/65 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-black/85;
}

.hero-control:focus-visible {
  @apply outline-none ring-2 ring-primary-300;
}

.live-pip {
  @apply w-1.5 h-1.5 rounded-full bg-white;
  animation: blink 1.8s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
  .live-pip {
    animation: none;
  }
}

</style>
