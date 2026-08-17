<script setup>
/**
 * Sets the in/out markers of a recording against its source archive.
 *
 * A cut is a time range, not a rendered file: saving rewrites a playlist, so trimming is
 * non-destructive and repeatable. The editor therefore works in absolute wall clock, not
 * offsets into a video, because the archive is one continuous per-source timeline and a
 * recording is a window onto it.
 *
 * The preview deliberately covers a padded window *around* the cut rather than the cut
 * itself. Finding the real start means watching what happened before the current in
 * point, which a preview limited to the current markers cannot show.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Hls from 'hls.js';

const props = defineProps({
    recordingId: { type: [Number, String], required: true },
    startsAt: { type: String, default: null },
    endsAt: { type: String, default: null },
    /** Bounds of the archive: { from, to } ISO strings. Outside this there is nothing to cut. */
    available: { type: Object, default: () => ({ from: null, to: null }) },
    segmentSeconds: { type: Number, default: 2 },
    /** Minutes of archive shown on either side of the cut. */
    padMinutes: { type: Number, default: 5 },
});

const emit = defineEmits(['update:startsAt', 'update:endsAt']);

const video = ref(null);
const track = ref(null);
const root = ref(null);

let hls = null;

const playheadMs = ref(null);
const dragging = ref(null);
const loading = ref(false);
const loadError = ref(null);
/** Wall-clock instant the loaded preview begins at, for mapping currentTime -> absolute. */
const previewStartMs = ref(null);
/** Set while previewing a marker, so playback can be stopped at the right point. */
const stopAtMs = ref(null);

/**
 * How much archive the media element holds at once.
 *
 * A preview playlist names every segment it covers, so its size is linear in the span
 * asked for: at 2s segments a single day's archive is around 19,000 presigned URLs, which
 * is a playlist measured in megabytes for the server to sign and for hls.js to hold. The
 * picture therefore covers a window that travels with the playhead, and seeking outside it
 * loads the next window.
 *
 * The scrubber still spans the whole archive. It is a map of the timeline, not the media:
 * tying the two together is what made everything past the first window unreachable, since
 * the server truncates an over-long range and the video element then simply had no frames
 * there. Kept under the server's own ceiling so a request is never truncated at all.
 */
const PREVIEW_SPAN_MS = 2 * 60 * 60_000;

/** Wall-clock range the currently loaded preview covers, or null before the first load. */
const loaded = ref(null);

const toMs = (v) => (v ? new Date(v).getTime() : null);
const toIso = (ms) => new Date(ms).toISOString();

const archive = computed(() => {
    const from = toMs(props.available.from);
    const to = toMs(props.available.to);
    if (from === null || to === null || to <= from) return null;
    return { from, to };
});

const inMs = computed(() => toMs(props.startsAt));
const outMs = computed(() => toMs(props.endsAt));

/**
 * The visible span, held as state rather than derived from the markers.
 *
 * Deriving it was a mistake: dragging a handle moved the window, which rescaled the
 * timeline under the cursor, so the handle chased the pointer and the bar appeared to
 * grow. The coordinate system has to stay fixed while dragging. It only changes when the
 * operator asks for it, via fitToCut / showWholeArchive.
 */
const view = ref(null);

const window_ = computed(() => {
    if (!view.value) return null;
    return { ...view.value, span: view.value.to - view.value.from };
});

/** Frame the cut with context on either side, clamped to what the archive holds. */
const fitToCut = () => {
    if (!archive.value) return;
    const pad = props.padMinutes * 60_000;
    const from = Math.max(archive.value.from, (inMs.value ?? archive.value.from) - pad);
    const to = Math.min(archive.value.to, (outMs.value ?? archive.value.to) + pad);
    if (to > from) view.value = { from, to };
};

const showWholeArchive = () => {
    if (!archive.value) return;
    view.value = { from: archive.value.from, to: archive.value.to };
};

// Synchronously, not in onMounted: the template dereferences the window on its very first
// render, which happens before mounted hooks run.
fitToCut();

/** Zoom about the playhead, so the frame being examined stays put. */
const zoom = (factor) => {
    if (!view.value || !archive.value) return;
    const span = view.value.to - view.value.from;
    const centre = playheadMs.value ?? view.value.from + span / 2;
    const next = Math.max(60_000, Math.min(archive.value.to - archive.value.from, span * factor));
    let from = centre - (centre - view.value.from) * (next / span);
    let to = from + next;

    if (from < archive.value.from) { from = archive.value.from; to = from + next; }
    if (to > archive.value.to) { to = archive.value.to; from = to - next; }

    view.value = { from: Math.max(archive.value.from, from), to: Math.min(archive.value.to, to) };
};

const pct = (ms) => {
    if (!window_.value || ms === null) return null;
    return Math.min(100, Math.max(0, ((ms - window_.value.from) / window_.value.span) * 100));
};

const inPct = computed(() => pct(inMs.value));
const outPct = computed(() => pct(outMs.value));
const playheadPct = computed(() => pct(playheadMs.value));

const hoverMs = ref(null);
const hoverPct = computed(() => pct(hoverMs.value));

/**
 * Ruler ticks, aligned to real clock boundaries rather than to the edge of the view.
 *
 * Ticks landing on 01:05:00 rather than 01:04:37 are what makes a ruler readable: the
 * operator is matching against a programme time, not counting pixels. The interval is the
 * smallest that keeps the labels from colliding, so zooming in gives finer marks without
 * the labels ever overlapping.
 */
const TICK_STEPS = [
    1_000, 2_000, 5_000, 10_000, 15_000, 30_000,
    60_000, 120_000, 300_000, 600_000, 900_000, 1_800_000,
    3_600_000, 7_200_000, 21_600_000,
];

const tickInterval = computed(() => {
    if (!window_.value) return null;
    const target = window_.value.span / 10; // aim for about ten labels
    return TICK_STEPS.find((step) => step >= target) ?? TICK_STEPS[TICK_STEPS.length - 1];
});

const ticks = computed(() => {
    if (!window_.value || !tickInterval.value) return [];

    const step = tickInterval.value;
    const out = [];
    // Minor ticks at a quarter of the labelled interval give a sense of scale between
    // labels without adding clutter.
    const minor = step / 4;
    let cursor = Math.ceil(window_.value.from / minor) * minor;

    while (cursor <= window_.value.to && out.length < 400) {
        const major = cursor % step === 0;
        out.push({
            ms: cursor,
            left: pct(cursor),
            major,
            label: major ? (step < 60_000 ? formatClock(cursor) : formatClock(cursor).slice(0, 5)) : null,
        });
        cursor += minor;
    }

    return out;
});

const selectionStyle = computed(() => {
    if (inPct.value === null || outPct.value === null) return { display: 'none' };
    return { left: `${inPct.value}%`, width: `${Math.max(0, outPct.value - inPct.value)}%` };
});

const cutSeconds = computed(() =>
    inMs.value === null || outMs.value === null
        ? null
        : Math.max(0, Math.round((outMs.value - inMs.value) / 1000)),
);

const formatDuration = (s) => {
    if (s === null) return '--:--';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return h > 0
        ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
        : `${m}:${String(sec).padStart(2, '0')}`;
};

const formatClock = (ms) =>
    ms === null ? '--:--:--' : new Date(ms).toISOString().slice(11, 19);

const formatFull = (ms) =>
    ms === null ? '--' : new Date(ms).toISOString().replace('T', ' ').slice(0, 19) + 'Z';

/** Markers snap to the segment grid; nothing is ever cut inside a segment. */
const snap = (ms) => {
    if (!archive.value) return ms;
    const grid = props.segmentSeconds * 1000;
    return archive.value.from + Math.round((ms - archive.value.from) / grid) * grid;
};

const clampToArchive = (ms) =>
    !archive.value ? ms : Math.min(archive.value.to, Math.max(archive.value.from, ms));

const setMarker = (which, ms) => {
    let value = snap(clampToArchive(ms));

    // Keep the markers ordered and at least one segment apart, so a drag past the other
    // handle pushes rather than inverting the range.
    const grid = props.segmentSeconds * 1000;
    if (which === 'in' && outMs.value !== null) value = Math.min(value, outMs.value - grid);
    if (which === 'out' && inMs.value !== null) value = Math.max(value, inMs.value + grid);

    emit(which === 'in' ? 'update:startsAt' : 'update:endsAt', toIso(value));
};

const msFromClientX = (clientX) => {
    if (!window_.value || !track.value) return null;
    const rect = track.value.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
    return window_.value.from + ratio * window_.value.span;
};

/** The media window to load so that `ms` is playable, centred on it where the archive allows. */
const previewWindowFor = (ms) => {
    if (!archive.value) return null;
    const span = Math.min(PREVIEW_SPAN_MS, archive.value.to - archive.value.from);
    let from = ms - span / 2;
    if (from < archive.value.from) from = archive.value.from;
    if (from + span > archive.value.to) from = archive.value.to - span;
    return { from, to: from + span };
};

const isLoaded = (ms) => loaded.value !== null && ms >= loaded.value.from && ms <= loaded.value.to;

/** Absolute instant -> offset into the loaded window, applied to the media element. */
const seekLoaded = (ms) => {
    if (!video.value || previewStartMs.value === null) return;
    const offset = (ms - previewStartMs.value) / 1000;
    if (offset >= 0 && Number.isFinite(video.value.duration)) {
        video.value.currentTime = Math.min(offset, video.value.duration);
    }
};

/**
 * Move the video to an absolute instant, fetching another window first if that instant is
 * outside the one already loaded.
 */
const seekTo = (ms) => {
    const target = clampToArchive(ms);
    playheadMs.value = target;

    if (!video.value) return;

    if (!isLoaded(target)) {
        // Always the latest target, so a scrub that crosses the boundary several times
        // ends up loading the window it finished in rather than each one it passed over.
        seekAfterLoad = target;
        if (!loading.value) loadPreview(target);
        return;
    }

    seekLoaded(target);
};

/** Completes a seek that had to wait for its window, once the media can be seeked. */
const applyPendingSeek = () => {
    if (seekAfterLoad === null) return;
    const target = seekAfterLoad;

    // The operator kept scrubbing while this window loaded and has left it again. Leave the
    // target set and fetch the window they ended up in.
    if (!isLoaded(target)) {
        if (!loading.value) loadPreview(target);
        return;
    }

    seekAfterLoad = null;
    playheadMs.value = target;
    seekLoaded(target);

    if (playAfterSeek) {
        playAfterSeek = false;
        video.value?.play();
    }
};

/**
 * Seeks are coalesced to one per frame.
 *
 * A pointermove can fire far more often than the video can seek, and driving hls.js at
 * that rate makes scrubbing lurch as it cancels and refetches segments. Taking only the
 * latest position each frame keeps the picture tracking the cursor smoothly.
 */
let pendingSeekMs = null;
let seekFrame = null;
let resumeAfterScrub = false;

/** Instant to seek to once a window load finishes, when it landed outside the loaded one. */
let seekAfterLoad = null;
/** Whether that deferred seek should start playback when it lands. */
let playAfterSeek = false;
/** Discards the result of a load that a later one has already superseded. */
let loadToken = 0;

const flushSeek = () => {
    seekFrame = null;
    if (pendingSeekMs === null) return;
    seekTo(pendingSeekMs);
    pendingSeekMs = null;
};

const queueSeek = (ms) => {
    pendingSeekMs = ms;
    seekFrame ??= requestAnimationFrame(flushSeek);
};

const beginDrag = (what) => {
    dragging.value = what;
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp, { once: true });
};

const onTrackPointerDown = (event) => {
    const ms = msFromClientX(event.clientX);
    if (ms === null) return;

    // Grab whichever handle is nearer, so the track behaves like a range slider rather
    // than only scrubbing.
    const near = (a) => (a === null ? Infinity : Math.abs(a - ms));
    const grabbable = window_.value.span * 0.02;

    if (Math.min(near(inMs.value), near(outMs.value)) < grabbable) {
        beginDrag(near(inMs.value) <= near(outMs.value) ? 'in' : 'out');
        return;
    }

    // Anywhere else scrubs, and keeps scrubbing while the button is held. Playback pauses
    // for the duration and resumes afterwards if it was running, which is what every
    // editor does: hearing audio stutter through a scrub is worse than silence.
    stopAtMs.value = null;
    resumeAfterScrub = video.value ? !video.value.paused : false;
    video.value?.pause();

    event.currentTarget.setPointerCapture?.(event.pointerId);
    beginDrag('playhead');
    seekTo(ms);
};

const onPointerMove = (event) => {
    if (!dragging.value) return;
    const ms = msFromClientX(event.clientX);
    if (ms === null) return;

    if (dragging.value === 'playhead') {
        queueSeek(ms);
        return;
    }

    setMarker(dragging.value, ms);
};

const onPointerUp = () => {
    const was = dragging.value;
    dragging.value = null;
    window.removeEventListener('pointermove', onPointerMove);

    if (was === 'playhead') {
        if (seekFrame !== null) {
            cancelAnimationFrame(seekFrame);
            seekFrame = null;
        }
        flushSeek();

        if (resumeAfterScrub) video.value?.play();
        resumeAfterScrub = false;
    }
};

const grabHandle = (which, event) => {
    event.stopPropagation();
    beginDrag(which);
};

const nudge = (which, segments) => {
    const current = which === 'in' ? inMs.value : outMs.value;
    if (current === null) return;
    setMarker(which, current + segments * props.segmentSeconds * 1000);
};

const markHere = (which) => {
    if (playheadMs.value === null) return;
    setMarker(which, playheadMs.value);
};

/**
 * Play from an instant, loading its window first if need be.
 *
 * Calling play() straight after seekTo only works when the instant is already loaded. A cut
 * whose ends fall in different windows - anything longer than the preview span - would
 * otherwise start playing the outgoing window while the right one was still in flight.
 */
const playFrom = (ms) => {
    seekTo(ms);
    if (seekAfterLoad !== null) {
        playAfterSeek = true;
        return;
    }
    video.value?.play();
};

/** Play from the in point, which is what a viewer will see first. */
const previewStart = () => {
    if (inMs.value === null) return;
    stopAtMs.value = inMs.value + 10_000;
    playFrom(inMs.value);
};

/** Play the last few seconds, which is where a bad out point actually shows. */
const previewEnd = () => {
    if (outMs.value === null) return;
    stopAtMs.value = outMs.value;
    playFrom(outMs.value - 5_000);
};

const togglePlay = () => {
    if (!video.value) return;
    stopAtMs.value = null;
    video.value.paused ? video.value.play() : video.value.pause();
};

const onTimeUpdate = () => {
    if (!video.value || previewStartMs.value === null) return;
    // A window is still loading for a seek that has already been requested. Reporting the
    // outgoing window's position would drag the playhead back to where it was.
    if (seekAfterLoad !== null) return;
    playheadMs.value = previewStartMs.value + video.value.currentTime * 1000;

    if (stopAtMs.value !== null && playheadMs.value >= stopAtMs.value) {
        video.value.pause();
        stopAtMs.value = null;
    }
};

/**
 * Shortcuts are scoped to the editor, so clicking anywhere in it claims focus.
 *
 * Without this the keys only worked once something inside happened to be focused, which
 * from the outside looks like space simply not working.
 */
const focusEditor = () => root.value?.focus({ preventScroll: true });

const onKeydown = (event) => {
    // Never hijack typing in the surrounding form.
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || event.target?.isContentEditable) return;
    if (!root.value?.contains(event.target)) return;

    const step = event.shiftKey ? 5 : 1;
    const handled = () => {
        event.preventDefault();
        event.stopPropagation();
    };

    switch (event.key.toLowerCase()) {
        case 'i': markHere('in'); return handled();
        case 'o': markHere('out'); return handled();
        case 'k': case ' ': togglePlay(); return handled();
        case 'j': seekTo((playheadMs.value ?? 0) - step * 1000); return handled();
        case 'l': seekTo((playheadMs.value ?? 0) + step * 1000); return handled();
        case 'arrowleft': seekTo((playheadMs.value ?? 0) - props.segmentSeconds * 1000); return handled();
        case 'arrowright': seekTo((playheadMs.value ?? 0) + props.segmentSeconds * 1000); return handled();
        case '[': nudge('in', -step); return handled();
        case ']': nudge('in', step); return handled();
        case '{': nudge('out', -step); return handled();
        case '}': nudge('out', step); return handled();
        case 'home': previewStart(); return handled();
        case 'end': previewEnd(); return handled();
    }
};

/**
 * hls.js rather than a bare <video src>: browsers other than Safari have no native HLS,
 * and a raw .m3u8 in a src attribute fails with "no video with supported format found".
 */
const loadPreview = async (centreMs = null) => {
    if (!video.value) return;

    const centre = centreMs ?? playheadMs.value ?? inMs.value ?? archive.value?.from;
    const range = centre === null || centre === undefined ? null : previewWindowFor(centre);
    if (!range) return;

    const url =
        route('manage.recordings.preview', props.recordingId) +
        `?from=${encodeURIComponent(toIso(range.from))}` +
        `&to=${encodeURIComponent(toIso(range.to))}&rendition=hd`;

    const token = ++loadToken;
    loading.value = true;
    loadError.value = null;

    // The playlist's first segment can start slightly before the requested window, since
    // selection is by segment start. Read the real start so currentTime maps to the right
    // instant instead of drifting by up to one segment.
    let startMs;
    let servedTo = range.to;
    try {
        const response = await fetch(url, { headers: { Accept: 'application/vnd.apple.mpegurl' } });
        if (!response.ok) throw new Error(`Preview unavailable (${response.status})`);
        const text = await response.text();

        const match = text.match(/#EXT-X-PROGRAM-DATE-TIME:(.+)/);
        startMs = match ? new Date(match[1].trim()).getTime() : range.from;

        // The server caps how long a range it will render. Believing the request instead of
        // the response is what let the playhead run past the end of the actual media.
        const to = Date.parse(response.headers.get('X-Preview-To') ?? '');
        if (Number.isFinite(to)) servedTo = Math.min(range.to, to);
    } catch (e) {
        if (token !== loadToken) return;
        loading.value = false;
        loadError.value = e.message;
        // Otherwise a failed window leaves a seek pending forever, and onTimeUpdate stays
        // muted for the rest of the session.
        seekAfterLoad = null;
        playAfterSeek = false;
        return;
    }

    // A newer window was asked for while this one was in flight; that one owns the player.
    if (token !== loadToken) return;

    previewStartMs.value = startMs;
    loaded.value = { from: Math.min(range.from, startMs), to: servedTo };

    // A window opens at currentTime 0, which is up to half a span before the instant it was
    // fetched for. Without a seek to that instant the first timeupdate reports the start of
    // the window and drags the playhead there, so pressing play jumps to the wrong place.
    seekAfterLoad ??= clampToArchive(centre);

    if (hls) {
        hls.destroy();
        hls = null;
    }

    // Seeking needs a duration, which only exists once metadata is in. Dropped first in case
    // a superseded load left one that will now never fire.
    video.value.removeEventListener('loadedmetadata', applyPendingSeek);
    video.value.addEventListener('loadedmetadata', applyPendingSeek, { once: true });

    if (Hls.isSupported()) {
        hls = new Hls({ enableWorker: true, lowLatencyMode: false, backBufferLength: 120 });
        hls.on(Hls.Events.ERROR, (_e, data) => {
            if (data.fatal) loadError.value = `Playback error: ${data.details}`;
        });
        hls.loadSource(url);
        hls.attachMedia(video.value);
    } else {
        // Safari plays HLS natively.
        video.value.src = url;
    }

    loading.value = false;
    if (playheadMs.value === null && inMs.value !== null) playheadMs.value = inMs.value;
};

onMounted(() => {
    loadPreview(inMs.value ?? archive.value?.from ?? null);
    window.addEventListener('keydown', onKeydown);
});

// The archive bounds arrive with the page, but guard against them landing late.
watch(archive, (value, previous) => {
    if (value && !previous) {
        fitToCut();
        loadPreview(inMs.value ?? value.from);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('pointermove', onPointerMove);
    hls?.destroy();
});

// Zooming and framing no longer touch the player at all. The view is the ruler's coordinate
// system; the media window follows the playhead, and seekTo fetches another one when the
// playhead leaves it. Reloading on every zoom used to tear the player down for a change that
// altered nothing about what was playing.
</script>

<template>
    <div ref="root" tabindex="-1" class="space-y-3 outline-none" @pointerdown="focusEditor">
        <!-- Guarded on the window rather than the archive, because the template
             dereferences the window and the two are not set at the same moment. -->
        <div v-if="!window_" class="rounded border border-hairline bg-surface-2 p-4 text-sm text-fg-3">
            No archive available for this source yet.
        </div>

        <template v-else>
            <!-- Picture and its controls are centred as one block; the timeline below
                 stays full width, where the extra pixels buy real precision. -->
            <div class="mx-auto w-full max-w-3xl space-y-3">
            <!-- Height-capped rather than width-driven: the timeline and the picture have
                 to be on screen together, or setting a marker means scrolling away from
                 the frame you are setting it against. -->
            <div class="relative flex max-h-[46vh] w-full items-center justify-center overflow-hidden rounded border border-hairline bg-black">
                <video
                    ref="video"
                    class="max-h-[46vh] w-full object-contain"
                    playsinline
                    muted
                    @timeupdate="onTimeUpdate"
                    @click="togglePlay"
                ></video>
                <div
                    v-if="loadError"
                    class="absolute inset-0 flex items-center justify-center bg-black/80 p-4 text-center text-sm text-danger-400"
                >
                    {{ loadError }}
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" class="cut-btn" @click="togglePlay">Play / pause</button>
                <button type="button" class="cut-btn" @click="previewStart">Preview start</button>
                <button type="button" class="cut-btn" @click="previewEnd">Preview last 5s</button>

                <span class="mx-1 text-fg-3">|</span>
                <!-- The view is fixed while editing, so these are the only way to reach
                     material outside the current frame. -->
                <button type="button" class="cut-btn" @click="zoom(0.5)" title="Zoom in around the playhead">+</button>
                <button type="button" class="cut-btn" @click="zoom(2)" title="Zoom out around the playhead">-</button>
                <button type="button" class="cut-btn" @click="fitToCut">Fit to cut</button>
                <button type="button" class="cut-btn" @click="showWholeArchive">Whole archive</button>

                <span class="ml-auto font-mono text-fg-2">{{ formatClock(playheadMs) }}</span>
            </div>
            </div>

            <!-- Spans a padded view of the archive, not just the cut, so material outside
                 the markers stays reachable. -->
            <div class="select-none rounded border border-hairline bg-surface-2">
                <!-- Ruler. Times are the archive's own clock, which is what the markers
                     are expressed in, so the operator reads one scale throughout. -->
                <div class="relative h-5 border-b border-hairline">
                    <template v-for="(tick, i) in ticks" :key="i">
                        <div
                            class="absolute bottom-0 w-px bg-fg-3/40"
                            :class="tick.major ? 'h-2' : 'h-1'"
                            :style="{ left: `${tick.left}%` }"
                        ></div>
                        <span
                            v-if="tick.label"
                            class="absolute top-0 -translate-x-1/2 font-mono text-[10px] leading-4 text-fg-3"
                            :style="{ left: `${tick.left}%` }"
                        >{{ tick.label }}</span>
                    </template>
                </div>

                <div
                    ref="track"
                    class="relative h-16 w-full cursor-pointer"
                    @pointerdown="onTrackPointerDown"
                    @pointermove="hoverMs = msFromClientX($event.clientX)"
                    @pointerleave="hoverMs = null"
                >
                    <!-- Grid, continuing the ruler down through the track so a marker can
                         be lined up against a time without moving the eye. -->
                    <div
                        v-for="(tick, i) in ticks"
                        :key="`g${i}`"
                        class="absolute inset-y-0 w-px"
                        :class="tick.major ? 'bg-fg-3/15' : 'bg-fg-3/5'"
                        :style="{ left: `${tick.left}%` }"
                    ></div>

                    <!-- Everything outside the cut is dimmed rather than the inside being
                         tinted: what will be published should read as the normal state. -->
                    <div
                        v-if="inPct !== null"
                        class="absolute inset-y-0 left-0 bg-surface-0/60"
                        :style="{ width: `${inPct}%` }"
                    ></div>
                    <div
                        v-if="outPct !== null"
                        class="absolute inset-y-0 right-0 bg-surface-0/60"
                        :style="{ width: `${100 - outPct}%` }"
                    ></div>

                    <div
                        class="absolute inset-y-0 border-y-2 border-primary-500/70 bg-primary-500/15"
                        :style="selectionStyle"
                    ></div>

                    <div
                        v-if="hoverPct !== null && !dragging"
                        class="pointer-events-none absolute inset-y-0 w-px bg-fg-1/30"
                        :style="{ left: `${hoverPct}%` }"
                    ></div>

                    <div
                        v-if="inPct !== null"
                        class="group absolute inset-y-0 -ml-1.5 flex w-3 cursor-ew-resize items-center justify-center rounded-sm bg-primary-500"
                        :style="{ left: `${inPct}%` }"
                        title="Drag the in point"
                        @pointerdown="grabHandle('in', $event)"
                    >
                        <div class="h-5 w-px bg-black/40"></div>
                    </div>
                    <div
                        v-if="outPct !== null"
                        class="group absolute inset-y-0 -ml-1.5 flex w-3 cursor-ew-resize items-center justify-center rounded-sm bg-primary-500"
                        :style="{ left: `${outPct}%` }"
                        title="Drag the out point"
                        @pointerdown="grabHandle('out', $event)"
                    >
                        <div class="h-5 w-px bg-black/40"></div>
                    </div>

                    <div
                        v-if="playheadPct !== null"
                        class="pointer-events-none absolute inset-y-0 w-0.5 bg-fg-1"
                        :style="{ left: `${playheadPct}%` }"
                    >
                        <div class="absolute -top-px -left-[3px] h-1.5 w-2 bg-fg-1"></div>
                    </div>
                </div>
            </div>

            <div class="flex justify-between font-mono text-[11px] text-fg-3">
                <span>{{ formatClock(window_.from) }}</span>
                <span class="text-fg-2">
                    cut {{ formatDuration(cutSeconds) }}
                    <span class="text-fg-3">of {{ formatDuration(Math.round(window_.span / 1000)) }} shown</span>
                    <span v-if="hoverMs !== null" class="ml-2">&middot; {{ formatClock(hoverMs) }}</span>
                </span>
                <span>{{ formatClock(window_.to) }}</span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" class="cut-btn" @click="markHere('in')">Set in (I)</button>
                        <button type="button" class="cut-btn" @click="nudge('in', -1)">-2s</button>
                        <button type="button" class="cut-btn" @click="nudge('in', 1)">+2s</button>
                    </div>
                    <p class="font-mono text-[11px] text-fg-2">in &nbsp;{{ formatFull(inMs) }}</p>
                </div>

                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" class="cut-btn" @click="markHere('out')">Set out (O)</button>
                        <button type="button" class="cut-btn" @click="nudge('out', -1)">-2s</button>
                        <button type="button" class="cut-btn" @click="nudge('out', 1)">+2s</button>
                    </div>
                    <p class="font-mono text-[11px] text-fg-2">out {{ formatFull(outMs) }}</p>
                </div>
            </div>

            <p class="font-mono text-[11px] text-fg-3">
                space play &middot; J/L &plusmn;1s &middot; &larr;/&rarr; &plusmn;{{ segmentSeconds }}s
                &middot; I/O set in/out &middot; [ ] in &middot; { } out &middot; Home/End preview
            </p>
        </template>
    </div>
</template>

<style scoped>
/* Tailwind v4 compiles each scoped block on its own, so @apply needs the theme in scope. */
@reference "../../../css/app.css";

.cut-btn {
    @apply rounded border border-hairline px-2 py-1 text-xs text-fg-2 transition hover:bg-surface-3;
}
</style>
