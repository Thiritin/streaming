<template>
    <div>
        <Head :title="recording.title" />

        <div class="watch-page">
            <!-- Left column: player, then everything about this recording -->
            <div class="min-w-0">
                <div class="relative bg-black sm:rounded-lg overflow-hidden sm:shadow-2xl">
                    <!-- Error State -->
                    <div v-if="error" class="absolute inset-0 flex flex-col items-center justify-center bg-black/90 z-10">
                        <FaVideoSlashIcon class="w-16 h-16 text-red-500 mb-4" />
                        <p class="text-white text-lg mb-4">{{ errorMessage }}</p>
                        <button
                            @click="retryPlayback"
                            class="px-4 py-2 bg-primary-600 hover:bg-primary-500 text-white rounded-lg transition-colors"
                        >
                            Retry Playback
                        </button>
                    </div>

                    <!-- Landing half of the shared-element morph from the archive
                         tile. See composables/useMediaHero.js. -->
                    <VideoPlayer
                        ref="playerRef"
                        :key="playerKey"
                        v-media-hero
                        :src="recording.m3u8_url"
                        :title="recording.title"
                        :poster="recording.thumbnail_url"
                        :is-live="false"
                        :autoplay="true"
                        :start-time="resumeAt"
                        @can-play="handleCanPlay"
                        @time-update="handleTimeUpdate"
                        @duration-change="mediaDuration = $event"
                        @pause="saveProgress"
                        @ended="handleEnded"
                        @error="handleError"
                    />

                    <!-- An offer, never an edit: the intermission still plays, and only a
                         press moves past it. Sits above the control bar, and leaves on its
                         own a few seconds before the segment ends so it never covers the
                         first frames of what comes back. -->
                    <transition name="skip-fade">
                        <button
                            v-if="activeSkip"
                            type="button"
                            class="skip-offer"
                            @click="skipCurrent"
                        >
                            Skip {{ activeSkip.label || 'intermission' }}
                            <FaPlayIcon class="h-3 w-3" />
                        </button>
                    </transition>

                    <!-- Up next, once this one has finished. Counts down rather than
                         cutting straight over, so leaving is one click and not a race.
                         The next recording's own still is behind it: what plays next
                         should be recognisable before it starts, not just named. -->
                    <div v-if="countdown !== null && nextUp" class="autoplay-card">
                        <img
                            v-if="nextUp.thumbnail_url"
                            :src="nextUp.thumbnail_url"
                            alt=""
                            aria-hidden="true"
                            class="autoplay-backdrop"
                        />

                        <div class="autoplay-inner">
                            <p class="autoplay-kicker">Up next</p>

                            <div class="autoplay-body">
                                <div class="autoplay-thumb">
                                    <img v-if="nextUp.thumbnail_url" :src="nextUp.thumbnail_url" alt="" />
                                    <TilePlaceholder v-else />
                                </div>

                                <div class="min-w-0">
                                    <p class="autoplay-title">{{ nextUp.title }}</p>
                                    <p v-if="nextUpMeta" class="autoplay-meta">{{ nextUpMeta }}</p>
                                </div>
                            </div>

                            <div class="autoplay-actions">
                                <button
                                    type="button"
                                    class="autoplay-ring"
                                    :aria-label="`Play ${nextUp.title} now`"
                                    @click="playNext"
                                >
                                    <svg class="autoplay-ring-svg" viewBox="0 0 48 48" aria-hidden="true">
                                        <circle class="autoplay-ring-track" cx="24" cy="24" r="21" />
                                        <circle
                                            class="autoplay-ring-sweep"
                                            cx="24"
                                            cy="24"
                                            r="21"
                                            :style="{ animationDuration: `${AUTOPLAY_SECONDS}s` }"
                                        />
                                    </svg>
                                    <FaPlayIcon class="ml-0.5 h-5 w-5 text-white" />
                                </button>

                                <div class="autoplay-side">
                                    <p class="autoplay-count tabular-nums">Playing in {{ countdown }}s</p>
                                    <button type="button" class="autoplay-secondary" @click="cancelAutoplay">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-4 sm:px-0 pt-5">
                    <h1 class="text-xl sm:text-2xl font-bold text-white">
                        {{ recording.title }}
                    </h1>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p v-if="sourceName" class="text-sm font-semibold text-white">{{ sourceName }}</p>
                    </div>

                    <!-- Description box: views, date and the text in one grey block,
                         clamped until it is asked to open. -->
                    <div class="description" :class="{ 'is-open': descriptionOpen }">
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-semibold text-primary-100">
                            <span v-if="recording.views">{{ formatViews(recording.views) }} views</span>
                            <span>{{ formatDate(recording.date) }}</span>
                            <span v-if="recording.duration" class="font-normal text-primary-300">
                                {{ formatDuration(recording.duration) }}
                            </span>
                        </p>

                        <p v-if="recording.description" ref="descriptionText" class="description-text">
                            {{ recording.description }}
                        </p>

                        <button
                            v-if="descriptionClamped"
                            type="button"
                            class="description-toggle"
                            @click="descriptionOpen = !descriptionOpen"
                        >
                            {{ descriptionOpen ? 'Show less' : '...more' }}
                        </button>
                    </div>

                    <!-- Navigation -->
                    <div class="mt-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <Link
                            :href="route('recordings.index')"
                            class="inline-flex items-center text-primary-400 hover:text-primary-200 transition-colors"
                        >
                            <FaArrowLeftIcon class="w-5 h-5 mr-2" />
                            Back to Archive
                        </Link>

                        <!-- Hosting Sponsor -->
                        <a
                            href="https://pawhost.de"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center gap-4 px-6 py-3 bg-primary-800/50 hover:bg-primary-800 border border-primary-700/50 rounded-xl transition-all group"
                        >
                            <span class="text-sm text-primary-400 uppercase tracking-wide font-medium">Hosting sponsored by</span>
                            <img
                                :src="pawHostLogo"
                                alt="PawHost"
                                class="h-10 opacity-80 group-hover:opacity-100 transition-opacity"
                            />
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right column: what else there is to watch. Below the player on
                 anything narrower than a desktop, which is where it belongs on a
                 phone. Not a queue and not a playlist - it is the rest of the
                 stage's programme - so it is named after where it comes from. -->
            <aside v-if="upNext.length" class="watch-rail">
                <div class="flex items-center justify-between gap-3 px-2 pb-2">
                    <h2 class="min-w-0 truncate text-sm font-semibold text-white">{{ railTitle }}</h2>

                    <label class="autoplay-toggle">
                        <input v-model="autoplayNext" type="checkbox" class="sr-only peer" />
                        <span class="toggle-track" aria-hidden="true"><span class="toggle-knob" /></span>
                        Autoplay
                    </label>
                </div>

                <RecordingRow v-for="item in upNext" :key="item.id" :recording="item" />
            </aside>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VideoPlayer from '@/Components/Player/VideoPlayer.vue';
import RecordingRow from '@/Components/Recordings/RecordingRow.vue';
import { rememberProgress } from '@/composables/useRecentProgress';
import TilePlaceholder from '@/Components/TilePlaceholder.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';
import FaArrowLeftIcon from '@/Components/Icons/FaArrowLeftIcon.vue';
import FaPlayIcon from '@/Components/Icons/FaPlayIcon.vue';
import pawHostLogo from '../../images/pawhost_white.svg';

defineOptions({
    layout: AuthenticatedLayout
});

const props = defineProps({
    recording: {
        type: Object,
        required: true
    },
    sourceName: {
        type: String,
        default: null
    },
    upNext: {
        type: Array,
        default: () => []
    },
    // Where this viewer left off, or 0. Guests always get 0: nothing is stored.
    resumeAt: {
        type: Number,
        default: 0
    },
    // Stretches this recording offers a way past, sorted and non-overlapping.
    skips: {
        type: Array,
        default: () => []
    }
});

const error = ref(false);
const errorMessage = ref('');
const descriptionOpen = ref(false);

// A description that already fits gets no "...more": the toggle would open onto
// exactly what is already on screen.
const descriptionText = ref(null);
const descriptionClamped = ref(false);

const measureDescription = () => {
    const element = descriptionText.value;

    descriptionClamped.value = Boolean(element) && element.scrollHeight > element.clientHeight + 1;
};

// Bumping this remounts VideoPlayer, which is the cleanest way to rebuild the
// provider and start the load from scratch.
const playerKey = ref(0);

const AUTOPLAY_KEY = 'archive:autoplay-next';
const AUTOPLAY_SECONDS = 8;

const autoplayNext = ref(true);
const countdown = ref(null);
let countdownTimer = null;

/*
 * What autoplay has already rolled through this tab.
 *
 * The rail is the rest of the same source, newest first, so the newest recording
 * on a source with two of them points at the other one and the other one points
 * straight back: a tab left alone played A, B, A, B until it was closed, and every
 * hop was a page render counted as a view. The chain is what has been played
 * without the viewer choosing anything, and autoplay skips past it - a source that
 * has run out of recordings the viewer has not seen ends rather than starting over.
 *
 * Session storage, so it is this tab's chain and it survives the reload a visit is.
 * Arriving at a recording that is not in the chain means the viewer picked it
 * themselves, which starts a new one.
 */
const CHAIN_KEY = 'archive:autoplay-chain';
const CHAIN_MAX = 50;

const readChain = () => {
    try {
        const raw = window.sessionStorage.getItem(CHAIN_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
};

const chain = ref([]);

const writeChain = (ids) => {
    chain.value = ids.slice(-CHAIN_MAX);

    try {
        window.sessionStorage.setItem(CHAIN_KEY, JSON.stringify(chain.value));
    } catch {
        // A tab with storage switched off just gets no memory of the chain.
    }
};

const syncChain = () => {
    const stored = readChain();

    chain.value = stored.includes(props.recording.id) ? stored : [];
};

const autoplayTarget = computed(
    () => props.upNext.find((item) => !chain.value.includes(item.id)) ?? null,
);

// What the card offers: the first one the chain has not been through, so the name
// on the card is the one the countdown plays. With everything on the rail already
// played it falls back to the rail's own lead, which a press can still take.
const nextUp = computed(() => autoplayTarget.value ?? props.upNext[0] ?? null);

const nextUpMeta = computed(() =>
    nextUp.value
        ? [nextUp.value.source_name, formatDuration(nextUp.value.duration)].filter(Boolean).join(' · ')
        : ''
);

// The rail is the rest of the same stage first, so name it that. "Up next" read as
// a queue somebody had put together, which is not what this is.
const railTitle = computed(() => (props.sourceName ? `More from ${props.sourceName}` : 'More in the archive'));

// Pressed, so it is a choice rather than a roll-on: the chain starts again from here.
const playNext = () => {
    if (!nextUp.value) return;

    cancelAutoplay();
    writeChain([]);
    router.visit(route('recordings.show', nextUp.value.id));
};

onMounted(() => {
    // Off is the only thing worth remembering; a missing key means the default.
    autoplayNext.value = window.localStorage.getItem(AUTOPLAY_KEY) !== 'off';
    syncChain();
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('resize', measureDescription);
    measureDescription();
});

watch(autoplayNext, (value) => {
    window.localStorage.setItem(AUTOPLAY_KEY, value ? 'on' : 'off');

    if (!value) cancelAutoplay();
});

const cancelAutoplay = () => {
    clearInterval(countdownTimer);
    countdownTimer = null;
    countdown.value = null;
};

const startAutoplay = () => {
    const target = autoplayTarget.value;

    if (!autoplayNext.value || !target) return;

    countdown.value = AUTOPLAY_SECONDS;

    countdownTimer = setInterval(() => {
        countdown.value -= 1;

        if (countdown.value <= 0) {
            cancelAutoplay();
            writeChain([...chain.value, props.recording.id, target.id]);
            router.visit(route('recordings.show', target.id));
        }
    }, 1000);
};

/*
 * Skip points.
 *
 * The offer stops a few seconds before the segment ends, so pressing it can never
 * land the viewer past the first frames of what they were waiting for, and a button
 * does not flash away as the picture comes back.
 */
const SKIP_TAIL = 3;

const playerRef = ref(null);

const seekTo = (seconds) => playerRef.value?.seek(seconds);

const activeSkip = computed(() =>
    props.skips.find(
        (segment) => currentTime.value >= segment.start && currentTime.value < segment.end - SKIP_TAIL
    ) ?? null
);

const skipCurrent = () => {
    if (!activeSkip.value) return;

    seekTo(activeSkip.value.end);
};

/*
 * Playback position. Signed-in viewers only, and written no more than once every
 * POST_EVERY seconds: the player fires time-update several times a second, and
 * this is a row that only has to be roughly right.
 */
const POST_EVERY = 15;

const page = usePage();
const canSaveProgress = computed(() => Boolean(page.props.auth?.user));

const currentTime = ref(props.resumeAt);
// Whether this visit has played past where it resumed. See handleEnded.
const watched = ref(false);
let lastSaved = props.resumeAt;

/*
 * What actually played, which is not always what the record says.
 *
 * `recordings.duration` is metadata and can be stale or wrong; the media element
 * knows its own length exactly. Progress is reported against this one, so the bar on
 * a tile and the bar under the player are measuring the same thing. Without it the
 * two disagree by whatever the record is wrong by - a recording watched to its end
 * shows a fifth of a bar on the archive page.
 */
const mediaDuration = ref(null);

const saveProgress = () => {
    if (!canSaveProgress.value) return;

    /*
     * The first time-updates of a fresh load report 0, before the resume seek has
     * landed. Writing one of those - which is what leaving straight after opening a
     * recording used to do - wipes the position and drops it off Continue watching.
     */
    if (currentTime.value < 1) return;

    if (Math.abs(currentTime.value - lastSaved) < 1) return;

    lastSaved = currentTime.value;

    // Remembered for the archive page as well as posted, so coming straight back
    // to a grid Inertia has cached does not redraw a bar from before this visit.
    rememberProgress(props.recording.id, {
        position: currentTime.value,
        duration: mediaDuration.value,
        completed: mediaDuration.value ? currentTime.value / mediaDuration.value >= 0.97 : false,
    });

    window.axios
        .put(route('recordings.progress', props.recording.id), {
            position: Math.floor(currentTime.value),
            duration: mediaDuration.value ? Math.round(mediaDuration.value) : null,
        })
        .catch(() => {});
};

/*
 * One recording rolling into the next is an Inertia visit to the same page component, so
 * nothing here is unmounted and nothing resets on its own. Everything that describes the
 * recording being watched has to be put back by hand, or the next one is measured against
 * the last one's length, saved against its position, and treated as already watched -
 * which is what let it end on arrival and roll straight on again.
 */
watch(
    () => props.recording.id,
    () => {
        currentTime.value = props.resumeAt;
        lastSaved = props.resumeAt;
        watched.value = false;
        mediaDuration.value = null;
        cancelAutoplay();
        syncChain();
    },
);

const handleTimeUpdate = (time) => {
    if (time > props.resumeAt + 2) watched.value = true;

    currentTime.value = time;

    if (canSaveProgress.value && Math.abs(time - lastSaved) >= POST_EVERY) {
        saveProgress();
    }
};

// The tab going away is the last chance to record where the viewer got to, and
// pagehide is the only one of these events that fires reliably on mobile Safari.
const onPageHide = () => saveProgress();

onBeforeUnmount(() => {
    window.removeEventListener('pagehide', onPageHide);
    window.removeEventListener('resize', measureDescription);
    cancelAutoplay();
    saveProgress();
});

const handleCanPlay = () => {
    error.value = false;
};

const handleEnded = () => {
    lastSaved = 0;
    currentTime.value = mediaDuration.value ?? props.recording.duration ?? currentTime.value;
    saveProgress();

    // Only roll on if this visit actually played something. A stored position past
    // the end of the media - which is what a stale `duration` on the recording
    // produces - otherwise resumes at the end, ends on arrival and takes the viewer
    // to the next recording before they have seen a frame of this one.
    if (!watched.value) return;

    startAutoplay();
};

const handleError = (detail) => {
    console.error('Recording playback error:', detail);
    error.value = true;
    errorMessage.value = detail?.message || 'This recording could not be played.';
};

const retryPlayback = () => {
    error.value = false;
    playerKey.value += 1;
};

const formatDuration = (seconds) => {
    if (!seconds) return '';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${minutes}:${String(secs).padStart(2, '0')}`;
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatViews = (views) => {
    if (views < 1000) {
        return views.toString();
    } else if (views < 1000000) {
        return (views / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    } else {
        return (views / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
};
</script>

<style scoped>
@reference "../../css/app.css";

/*
 * One column until there is room for the rail beside the player. The player keeps
 * a max width of its own so it does not stretch to a cinema on an ultrawide.
 */
.watch-page {
    @apply mx-auto grid max-w-[1600px] gap-6 px-0 pt-0 sm:px-4 sm:pt-8 lg:px-8;
    grid-template-columns: minmax(0, 1fr);
}

@media (min-width: 1280px) {
    .watch-page {
        grid-template-columns: minmax(0, 1fr) 400px;
    }
}

.watch-rail {
    @apply flex flex-col gap-1 px-2 pb-10 sm:px-0;
}

.description {
    @apply mt-4 rounded-xl bg-primary-800/60 p-4;
}

.description-text {
    @apply mt-2 whitespace-pre-wrap text-sm leading-relaxed text-primary-200;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.description.is-open .description-text {
    -webkit-line-clamp: unset;
    overflow: visible;
}

.description-toggle {
    @apply mt-2 text-sm font-semibold text-primary-300 transition-colors hover:text-white;
}

.autoplay-toggle {
    @apply inline-flex cursor-pointer items-center gap-2 text-sm text-primary-200;
}

.toggle-track {
    @apply relative block h-5 w-9 rounded-full bg-white/15 transition-colors;
}

.autoplay-toggle:has(:checked) .toggle-track {
    @apply bg-primary-500;
}

.toggle-knob {
    @apply absolute left-0.5 top-0.5 block h-4 w-4 rounded-full bg-white transition-transform;
}

.autoplay-toggle:has(:checked) .toggle-knob {
    transform: translateX(1rem);
}

.autoplay-toggle:has(:focus-visible) .toggle-track {
    @apply ring-2 ring-primary-300;
}

/* Above the control bar rather than beside it: vidstack owns the bottom strip, and
   an offer that overlaps the scrubber is one a viewer presses by accident. */
.skip-offer {
    @apply absolute bottom-20 right-4 z-20 inline-flex items-center gap-2 rounded-md border border-white/25 bg-black/70 px-4 py-2 text-sm font-semibold text-white backdrop-blur-sm transition-colors;
}

.skip-offer:hover {
    @apply border-white/60 bg-black/85;
}

.skip-fade-enter-active,
.skip-fade-leave-active {
    transition: opacity 160ms ease, transform 160ms ease;
}

.skip-fade-enter-from,
.skip-fade-leave-to {
    opacity: 0;
    transform: translateY(6px);
}






.autoplay-card {
    @apply absolute inset-0 z-20 flex items-center justify-center overflow-hidden bg-black/80 px-6 backdrop-blur-sm;
}

/* The next recording's own still, blown up and pushed back behind the card, so the
   overlay is coloured by what is about to play rather than being a black sheet. */
.autoplay-backdrop {
    @apply absolute inset-0 h-full w-full scale-110 object-cover opacity-25;
    filter: blur(18px) saturate(1.1);
}

.autoplay-inner {
    @apply relative z-10 flex w-full max-w-md flex-col gap-4;
}

.autoplay-kicker {
    @apply text-xs font-semibold uppercase tracking-[0.14em] text-primary-300;
}

.autoplay-body {
    @apply flex items-center gap-4;
}

.autoplay-thumb {
    @apply relative aspect-video w-32 shrink-0 overflow-hidden rounded-lg bg-primary-900 ring-1 ring-white/10 sm:w-40;
}

.autoplay-thumb img {
    @apply h-full w-full object-cover;
}

.autoplay-title {
    @apply line-clamp-2 text-base font-semibold text-white sm:text-lg;
}

.autoplay-meta {
    @apply mt-1 truncate text-sm text-primary-300;
}

.autoplay-actions {
    @apply flex items-center gap-4;
}

.autoplay-side {
    @apply flex flex-col items-start gap-2;
}

.autoplay-count {
    @apply text-sm text-primary-200;
}

/* The countdown is the ring, not the number: a sweep reads at a glance from across
   a room, and the button it is drawn around is the thing to press to skip it. */
.autoplay-ring {
    @apply relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white/10 transition-colors;
}

.autoplay-ring:hover {
    @apply bg-white/20;
}

.autoplay-ring:focus-visible {
    @apply outline-none ring-2 ring-primary-300;
}

.autoplay-ring-svg {
    @apply absolute inset-0 h-full w-full -rotate-90;
}

.autoplay-ring-track {
    fill: none;
    stroke: color-mix(in oklch, white 20%, transparent);
    stroke-width: 3;
}

.autoplay-ring-sweep {
    fill: none;
    stroke: var(--color-primary-400);
    stroke-width: 3;
    stroke-linecap: round;
    /* 2 * pi * 21 */
    stroke-dasharray: 131.95;
    stroke-dashoffset: 131.95;
    animation: autoplay-sweep linear forwards;
}

@keyframes autoplay-sweep {
    to {
        stroke-dashoffset: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .autoplay-ring-sweep {
        animation: none;
        stroke-dashoffset: 0;
    }
}

.autoplay-secondary {
    @apply rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-primary-100 transition-colors hover:bg-white/20;
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
