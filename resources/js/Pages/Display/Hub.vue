<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
  keyName: { type: String, required: true },
  screenName: { type: String, default: null },
  sources: { type: Array, required: true },
  featuredSlug: { type: String, default: null },
  directedSlug: { type: String, default: null },
});

const sources = ref([...props.sources]);
const directedSlug = ref(props.directedSlug);
const copied = ref(null);
let pollTimer = null;
let copiedTimer = null;

/*
 * On air means both: a show is running on the channel and the feed is arriving.
 * A channel that only ingests - a hall camera through setup - is listed but cannot
 * be started, and carries no playback URLs.
 */
const onAir = (source) => Boolean(source?.isAvailable && source?.isOnline);
const onAirCount = computed(() => sources.value.filter(onAir).length);

const channelState = (source) => {
  if (!source.isAvailable) return 'No show on air';
  return source.isOnline ? 'Live' : 'Waiting for stream';
};

/*
 * A screen parked on the hub can be sent somewhere from /manage, but it cannot obey:
 * fullscreen and audio need a gesture, so all this page can do is say so and make the
 * button the obvious one to press.
 */
const directed = computed(
  () => sources.value.find((s) => s.slug === directedSlug.value) ?? null,
);

const startPlayback = (slug = null) => {
  if (slug && !sources.value.find((s) => s.slug === slug)?.isAvailable) return;

  /*
   * This click is what makes the kiosk work. Browsers only allow fullscreen and
   * audible playback off a real user gesture, so the player page cannot put itself
   * fullscreen on a cold load - it has to be entered from here.
   */
  const target = slug ? route('display.play', { source: slug }) : route('display.play');

  const el = document.documentElement;
  const request = el.requestFullscreen ?? el.webkitRequestFullscreen;

  if (request) {
    Promise.resolve(request.call(el)).catch(() => {
      // A screen with fullscreen blocked by policy still gets the player.
    });
  }

  router.visit(target);
};

const copy = async (value, id) => {
  try {
    await navigator.clipboard.writeText(value);
    copied.value = id;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => { copied.value = null; }, 2000);
  } catch (e) {
    // Clipboard access needs a secure context; the input is selectable regardless.
  }
};

const absolute = (url) => (url?.startsWith('http') ? url : `${window.location.origin}${url}`);

const signingOut = ref(false);

/*
 * Confirmed, because the code has to be typed in again afterwards and the person
 * standing at the screen may not have it on them.
 */
const signOut = () => {
  if (signingOut.value) return;

  if (!window.confirm('Sign this screen out? The display code has to be entered again.')) {
    return;
  }

  signingOut.value = true;
  router.post(route('display.leave'), {}, { onFinish: () => { signingOut.value = false; } });
};

/*
 * Kiosk mode is the only way a screen comes back up fullscreen and audible after a
 * reboot, since neither is allowed without a user gesture. Built here rather than in
 * the template so it can be copied as one line.
 */
const kioskCommand = computed(
  () => `chromium --kiosk --autoplay-policy=no-user-gesture-required "${absolute(route('display.play'))}"`,
);

const pollState = async () => {
  try {
    const response = await fetch(route('display.state', { page: 'hub' }), {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) return;

    const data = await response.json();
    sources.value = data.sources;
    directedSlug.value = data.directedSlug ?? null;
  } catch (e) {
    // Retried on the next tick.
  }
};

onMounted(() => { pollTimer = setInterval(pollState, 15000); });
onBeforeUnmount(() => { clearInterval(pollTimer); clearTimeout(copiedTimer); });
</script>

<template>
  <Head><title>Display setup</title></Head>

  <div class="min-h-screen bg-primary-950 text-primary-100">
    <div class="mx-auto max-w-5xl px-6 py-10">
      <header class="mb-8">
        <p class="text-xs uppercase tracking-widest text-primary-500">Display</p>
        <h1 class="mt-1 text-3xl font-bold text-white">{{ screenName ?? keyName }}</h1>
        <p class="mt-2 text-primary-400">
          {{ onAirCount }} of {{ sources.length }} channels on air. A channel opens when a
          show on it goes live. This screen stays signed in until the key is revoked.
        </p>
      </header>

      <div
        v-if="directed"
        class="mb-6 flex flex-wrap items-center gap-4 rounded-lg border border-primary-600 bg-primary-900 p-4"
      >
        <div class="flex-1">
          <p class="font-medium text-white">Sent to {{ directed.name }}</p>
          <p class="text-sm text-primary-400">
            Control asked this screen to show {{ directed.name }}. Playback has to be started
            here, because a browser will not go fullscreen or unmute on its own.
          </p>
        </div>
        <button
          type="button"
          :disabled="!directed.isAvailable"
          class="rounded-lg bg-primary-600 px-4 py-2 font-semibold text-white transition hover:bg-primary-500 disabled:cursor-not-allowed disabled:bg-primary-800 disabled:text-primary-500"
          @click="startPlayback(directed.slug)"
        >
          {{ directed.isAvailable ? `Start ${directed.name}` : 'Waiting for a show' }}
        </button>
      </div>

      <!-- Square buttons: the primary action, then one per source. -->
      <section class="mb-10 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <button
          type="button"
          class="flex aspect-square flex-col items-center justify-center gap-2 rounded-lg bg-primary-600 p-4 text-center font-semibold text-white transition hover:bg-primary-500"
          @click="startPlayback()"
        >
          <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          Start playback
        </button>

        <button
          v-for="source in sources"
          :key="source.slug"
          type="button"
          :disabled="!source.isAvailable"
          class="flex aspect-square flex-col items-center justify-center gap-2 rounded-lg border p-4 text-center transition"
          :class="!source.isAvailable
            ? 'cursor-not-allowed border-primary-800 bg-primary-900/30 text-primary-600'
            : source.isOnline
              ? 'border-primary-700 bg-primary-900 hover:border-primary-500'
              : 'border-primary-800 bg-primary-900/50 text-primary-500'"
          @click="startPlayback(source.slug)"
        >
          <span
            class="h-2.5 w-2.5 rounded-full"
            :class="onAir(source) ? 'bg-green-500' : 'bg-primary-600'"
          ></span>
          <span class="font-medium">{{ source.name }}</span>
          <span class="text-xs uppercase tracking-wide">
            {{ channelState(source) }}<template v-if="source.isFeatured"> · Featured</template>
          </span>
        </button>
      </section>

      <section>
        <h2 class="mb-1 text-lg font-semibold text-white">Open in VLC or another player</h2>
        <p class="mb-4 text-sm text-primary-400">
          Paste a URL into VLC with Media &gt; Open Network Stream. Use a fixed quality if the
          player handles quality switching badly; the ladder is the better choice otherwise.
        </p>

        <div v-for="source in sources" :key="source.slug" class="mb-4 rounded-lg border border-primary-800 bg-primary-900 p-4">
          <div class="mb-3 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full" :class="onAir(source) ? 'bg-green-500' : 'bg-primary-600'"></span>
            <h3 class="font-medium text-white">{{ source.name }}</h3>
          </div>

          <!-- No show on the channel means no token was issued, so there is nothing
               to paste. Saying so beats handing over a URL that answers 404. -->
          <p v-if="!source.isAvailable" class="text-sm text-primary-500">
            No show on air. URLs appear here once a show on this channel goes live.
          </p>

          <template v-else>
            <div
              v-for="entry in [
                { id: `${source.slug}-master`, label: 'Adaptive', url: source.url },
                { id: `${source.slug}-fhd`, label: '1080p', url: source.renditions?.fhd },
                { id: `${source.slug}-hd`, label: '720p', url: source.renditions?.hd },
                { id: `${source.slug}-sd`, label: '480p', url: source.renditions?.sd },
              ]"
              :key="entry.id"
              class="mb-2 flex items-center gap-2 last:mb-0"
            >
              <span class="w-20 shrink-0 text-xs uppercase tracking-wide text-primary-500">{{ entry.label }}</span>
              <input
                class="flex-1 truncate rounded border border-primary-700 bg-primary-950 px-2 py-1 font-mono text-xs text-primary-300"
                :value="absolute(entry.url)"
                readonly
                @focus="$event.target.select()"
              />
              <button
                type="button"
                class="shrink-0 rounded bg-primary-800 px-3 py-1 text-xs text-primary-200 hover:bg-primary-700"
                @click="copy(absolute(entry.url), entry.id)"
              >
                {{ copied === entry.id ? 'Copied' : 'Copy' }}
              </button>
            </div>
          </template>
        </div>
      </section>

      <section class="mt-10 rounded-lg border border-primary-800 bg-primary-900/50 p-4 text-sm text-primary-400">
        <h2 class="mb-2 font-medium text-primary-200">Screens that must survive a reboot</h2>
        <p>
          Fullscreen and audio need a click, so a screen that restarts on its own comes back
          muted and windowed. Launch the browser in kiosk mode instead:
        </p>

        <div class="mt-3 flex items-start gap-2">
          <code class="flex-1 overflow-x-auto whitespace-pre rounded bg-primary-950 p-2 font-mono text-xs text-primary-300">{{ kioskCommand }}</code>
          <button
            type="button"
            class="shrink-0 rounded bg-primary-800 px-3 py-2 text-xs text-primary-200 hover:bg-primary-700"
            @click="copy(kioskCommand, 'kiosk')"
          >
            {{ copied === 'kiosk' ? 'Copied' : 'Copy' }}
          </button>
        </div>

        <p class="mt-3 text-xs text-primary-500">
          On macOS the binary is
          <code class="font-mono">/Applications/Google Chrome.app/Contents/MacOS/Google Chrome</code>;
          on Windows it is <code class="font-mono">chrome.exe</code>. The flags are the same.
        </p>
      </section>

      <section class="mt-10 flex items-center justify-between gap-4 border-t border-primary-800 pt-6">
        <p class="text-sm text-primary-500">
          Done with this screen, or handing the device back? Sign it out and the code is
          needed again.
        </p>
        <button
          type="button"
          :disabled="signingOut"
          class="shrink-0 rounded-lg border border-primary-700 px-4 py-2 text-sm font-medium text-primary-300 transition hover:border-red-500 hover:text-red-400 disabled:opacity-50"
          @click="signOut"
        >
          Sign out this screen
        </button>
      </section>
    </div>
  </div>
</template>
