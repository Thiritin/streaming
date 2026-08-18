<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Hls from 'hls.js';

const props = defineProps({
  sources: { type: Array, required: true },
  initialSlug: { type: String, default: null },
});

const MAX_RECOVERIES = 3;
const STATE_POLL_MS = 20000;
const OFFLINE_GRACE_MS = 45000;
const MUTED_STORAGE_KEY = 'display.muted';

const player = ref(null);
const sources = ref([...props.sources]);
const currentSlug = ref(props.initialSlug);
const muted = ref(true);
const showChrome = ref(false);
const failed = ref(false);

let hlsInstance = null;
let recoveries = 0;
let pollTimer = null;
let chromeTimer = null;
let offlineSince = null;

const current = computed(() => sources.value.find((s) => s.slug === currentSlug.value) ?? null);
const onlineSources = computed(() => sources.value.filter((s) => s.isOnline));

const teardown = () => {
  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }
  if (player.value) {
    player.value.pause();
    player.value.removeAttribute('src');
  }
};

const play = async () => {
  teardown();

  const el = player.value;
  const url = current.value?.url;
  if (!el || !url) return;

  failed.value = false;
  recoveries = 0;
  el.muted = muted.value;

  if (Hls.isSupported()) {
    hlsInstance = new Hls({
      enableWorker: true,
      lowLatencyMode: false,
      maxBufferLength: 12,
      backBufferLength: 30,
    });
    hlsInstance.loadSource(url);
    hlsInstance.attachMedia(el);
    hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
      el.play().catch(() => { failed.value = true; });
    });
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

      failed.value = true;
    });
  } else if (el.canPlayType('application/vnd.apple.mpegurl')) {
    el.src = url;
    el.play().catch(() => { failed.value = true; });
  }
};

const switchTo = (slug) => {
  if (slug === currentSlug.value) return;
  currentSlug.value = slug;
  offlineSince = null;
};

/*
 * An unattended screen must never sit on a dead stream, but a publisher reconnect
 * is routine and takes a few seconds. So the channel only changes once a source has
 * been down for the whole grace window; anything shorter and a hiccup would move
 * every screen in the building.
 */
const reconcile = () => {
  const now = Date.now();
  const live = current.value?.isOnline ?? false;

  if (live) {
    offlineSince = null;
    return;
  }

  offlineSince ??= now;

  if (now - offlineSince < OFFLINE_GRACE_MS) return;

  const featured = sources.value.find((s) => s.isFeatured && s.isOnline);
  const next = featured ?? onlineSources.value[Math.floor(Math.random() * onlineSources.value.length)];

  if (next) switchTo(next.slug);
};

const pollState = async () => {
  try {
    const response = await fetch(route('display.state'), { headers: { Accept: 'application/json' } });
    if (!response.ok) return;

    const data = await response.json();
    sources.value = data.sources;
    reconcile();
  } catch (e) {
    // A display outlives transient network trouble; the next tick tries again.
  }
};

const toggleMuted = () => {
  muted.value = !muted.value;
  if (player.value) player.value.muted = muted.value;
  try {
    window.localStorage.setItem(MUTED_STORAGE_KEY, muted.value ? '1' : '0');
  } catch (e) {
    // Private browsing and locked-down kiosk profiles both refuse storage.
  }
};

const revealChrome = () => {
  showChrome.value = true;
  clearTimeout(chromeTimer);
  chromeTimer = setTimeout(() => { showChrome.value = false; }, 4000);
};

const exit = () => router.visit(route('display.hub'));

onMounted(() => {
  /*
   * The remembered setting can only be honoured when this page was opened from the
   * hub button, because that click is the gesture browsers require before audible
   * playback. On a cold reload the screen comes up muted whatever was stored, and
   * unmuting needs someone to touch it.
   */
  let remembered = '1';
  try {
    remembered = window.localStorage.getItem(MUTED_STORAGE_KEY) ?? '1';
  } catch (e) {
    // See toggleMuted.
  }
  muted.value = remembered !== '0';

  play();
  pollTimer = setInterval(pollState, STATE_POLL_MS);

  document.addEventListener('mousemove', revealChrome);
  document.addEventListener('keydown', revealChrome);
});

onBeforeUnmount(() => {
  teardown();
  clearInterval(pollTimer);
  clearTimeout(chromeTimer);
  document.removeEventListener('mousemove', revealChrome);
  document.removeEventListener('keydown', revealChrome);
});

watch(currentSlug, play);
</script>

<template>
  <Head>
    <title>{{ current?.name ?? 'Display' }}</title>
  </Head>

  <div class="fixed inset-0 bg-black">
    <video
      ref="player"
      class="h-full w-full object-contain"
      playsinline
      autoplay
      :muted="muted"
    ></video>

    <div
      v-if="!current?.isOnline || failed"
      class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-3 text-center"
    >
      <p class="text-2xl font-semibold text-white">{{ current?.name ?? 'No source' }}</p>
      <p class="text-primary-400">
        {{ failed ? 'Playback failed, retrying' : 'Offline, waiting for the stream' }}
      </p>
    </div>

    <!-- Chrome hides itself so a screen left alone shows only the picture. -->
    <transition
      enter-active-class="transition-opacity duration-200"
      leave-active-class="transition-opacity duration-500"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div v-show="showChrome" class="absolute inset-x-0 top-0 flex flex-wrap items-center gap-2 bg-gradient-to-b from-black/80 to-transparent p-4">
        <button
          v-for="source in sources"
          :key="source.slug"
          type="button"
          class="rounded px-3 py-2 text-sm font-medium transition-colors"
          :class="source.slug === currentSlug
            ? 'bg-primary-600 text-white'
            : 'bg-primary-800/80 text-primary-200 hover:bg-primary-700'"
          @click="switchTo(source.slug)"
        >
          <span
            class="mr-2 inline-block h-2 w-2 rounded-full"
            :class="source.isOnline ? 'bg-green-500' : 'bg-primary-600'"
          ></span>
          {{ source.name }}
        </button>

        <div class="ml-auto flex gap-2">
          <button
            type="button"
            class="rounded bg-primary-800/80 px-3 py-2 text-sm text-primary-200 hover:bg-primary-700"
            @click="toggleMuted"
          >
            {{ muted ? 'Unmute' : 'Mute' }}
          </button>
          <button
            type="button"
            class="rounded bg-primary-800/80 px-3 py-2 text-sm text-primary-200 hover:bg-primary-700"
            @click="exit"
          >
            Settings
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>
