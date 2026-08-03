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

/** Move the video to an absolute instant. */
const seekTo = (ms) => {
    playheadMs.value = ms;
    if (!video.value || previewStartMs.value === null) return;
    const offset = (ms - previewStartMs.value) / 1000;
    if (offset >= 0 && Number.isFinite(video.value.duration)) {
        video.value.currentTime = Math.min(offset, video.value.duration);
    }
};

const onTrackPointerDown = (event) => {
    const ms = msFromClientX(event.clientX);
    if (ms === null) return;

    // Grab whichever handle is nearer, so the track behaves like a range slider rather
    // than only scrubbing.
    const near = (a) => (a === null ? Infinity : Math.abs(a - ms));
    const grabbable = window_.value.span * 0.02;

    if (Math.min(near(inMs.value), near(outMs.value)) < grabbable) {
        dragging.value = near(inMs.value) <= near(outMs.value) ? 'in' : 'out';
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp, { once: true });
        return;
    }

    stopAtMs.value = null;
    seekTo(ms);
};

const onPointerMove = (event) => {
    if (!dragging.value) return;
    const ms = msFromClientX(event.clientX);
    if (ms !== null) setMarker(dragging.value, ms);
};

const onPointerUp = () => {
    dragging.value = null;
    window.removeEventListener('pointermove', onPointerMove);
};

const grabHandle = (which, event) => {
    event.stopPropagation();
    dragging.value = which;
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp, { once: true });
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

/** Play from the in point, which is what a viewer will see first. */
const previewStart = () => {
    if (inMs.value === null) return;
    stopAtMs.value = inMs.value + 10_000;
    seekTo(inMs.value);
    video.value?.play();
};

/** Play the last few seconds, which is where a bad out point actually shows. */
const previewEnd = () => {
    if (outMs.value === null) return;
    stopAtMs.value = outMs.value;
    seekTo(outMs.value - 5_000);
    video.value?.play();
};

const togglePlay = () => {
    if (!video.value) return;
    stopAtMs.value = null;
    video.value.paused ? video.value.play() : video.value.pause();
};

const onTimeUpdate = () => {
    if (!video.value || previewStartMs.value === null) return;
    playheadMs.value = previewStartMs.value + video.value.currentTime * 1000;

    if (stopAtMs.value !== null && playheadMs.value >= stopAtMs.value) {
        video.value.pause();
        stopAtMs.value = null;
    }
};

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
const loadPreview = async () => {
    if (!window_.value || !video.value) return;

    const url =
        route('manage.recordings.preview', props.recordingId) +
        `?from=${encodeURIComponent(toIso(window_.value.from))}` +
        `&to=${encodeURIComponent(toIso(window_.value.to))}&rendition=hd`;

    loading.value = true;
    loadError.value = null;

    // The playlist's first segment can start slightly before the requested window, since
    // selection is by segment start. Read the real start so currentTime maps to the right
    // instant instead of drifting by up to one segment.
    try {
        const text = await fetch(url, { headers: { Accept: 'application/vnd.apple.mpegurl' } })
            .then((r) => {
                if (!r.ok) throw new Error(`Preview unavailable (${r.status})`);
                return r.text();
            });
        const match = text.match(/#EXT-X-PROGRAM-DATE-TIME:(.+)/);
        previewStartMs.value = match ? new Date(match[1].trim()).getTime() : window_.value.from;
    } catch (e) {
        loading.value = false;
        loadError.value = e.message;
        return;
    }

    if (hls) {
        hls.destroy();
        hls = null;
    }

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
    if (inMs.value !== null) playheadMs.value = inMs.value;
};

onMounted(() => {
    loadPreview();
    window.addEventListener('keydown', onKeydown);
});

// The archive bounds arrive with the page, but guard against them landing late.
watch(archive, (value, previous) => {
    if (value && !previous) {
        fitToCut();
        loadPreview();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('pointermove', onPointerMove);
    hls?.destroy();
});

// Reloads only when the view is deliberately changed (fit, zoom, whole archive). Marker
// edits no longer touch the window at all, so dragging never tears down the player.
watch(
    () => (view.value ? `${view.value.from}-${view.value.to}` : null),
    (next, previous) => {
        if (next && previous && next !== previous) loadPreview();
    },
);
</script>

<template>
    <div ref="root" tabindex="-1" class="space-y-3 outline-none">
        <!-- Guarded on the window rather than the archive, because the template
             dereferences the window and the two are not set at the same moment. -->
        <div v-if="!window_" class="rounded border border-hairline bg-surface-2 p-4 text-sm text-fg-3">
            No archive is available for this source yet, so there is nothing to cut.
            Segments appear a few seconds behind live.
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

            <p class="text-[11px] leading-relaxed text-fg-3">
                <span class="font-mono">K</span>/space play,
                <span class="font-mono">J</span>/<span class="font-mono">L</span> back/forward 1s
                (shift 5s), <span class="font-mono">arrows</span> one segment,
                <span class="font-mono">I</span>/<span class="font-mono">O</span> set in/out at the
                playhead, <span class="font-mono">[</span> <span class="font-mono">]</span> nudge in,
                <span class="font-mono">{</span> <span class="font-mono">}</span> nudge out,
                <span class="font-mono">Home</span>/<span class="font-mono">End</span> preview
                start/end. Markers snap to {{ segmentSeconds }}s because segments are never split.
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
