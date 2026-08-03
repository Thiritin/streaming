<script setup>
/**
 * Sets the in/out markers of a recording against its source archive.
 *
 * A cut is a time range, not a rendered file: saving rewrites a playlist, so trimming is
 * non-destructive and can be redone any number of times. That is why this edits absolute
 * wall-clock instants rather than offsets into a video - the archive is a continuous
 * per-source timeline and a recording is a window onto it.
 *
 * Markers snap to the 2s segment grid because nothing is ever cut inside a segment; that
 * is what keeps the joins seamless. Sub-segment precision would be a lie.
 */
import { computed, ref, watch } from 'vue';

const props = defineProps({
    startsAt: { type: String, default: null },
    endsAt: { type: String, default: null },
    /** Bounds of the archive: { from, to } as ISO strings. Outside this there is nothing to cut. */
    available: { type: Object, default: () => ({ from: null, to: null }) },
    /** Playable master playlist for previewing, if the cut has been built. */
    previewUrl: { type: String, default: null },
    segmentSeconds: { type: Number, default: 2 },
});

const emit = defineEmits(['update:startsAt', 'update:endsAt']);

const video = ref(null);
const playhead = ref(0);

const toMs = (v) => (v ? new Date(v).getTime() : null);

const bounds = computed(() => {
    const from = toMs(props.available.from);
    const to = toMs(props.available.to);
    if (from === null || to === null || to <= from) return null;
    return { from, to, span: to - from };
});

const inMs = computed(() => toMs(props.startsAt));
const outMs = computed(() => toMs(props.endsAt));

/** Position within the archive window, 0-100, for the marker overlays. */
const pct = (ms) => {
    if (!bounds.value || ms === null) return null;
    return Math.min(100, Math.max(0, ((ms - bounds.value.from) / bounds.value.span) * 100));
};

const inPct = computed(() => pct(inMs.value));
const outPct = computed(() => pct(outMs.value));

const selectionStyle = computed(() => {
    if (inPct.value === null || outPct.value === null) return { display: 'none' };
    return { left: `${inPct.value}%`, width: `${Math.max(0, outPct.value - inPct.value)}%` };
});

const duration = computed(() => {
    if (inMs.value === null || outMs.value === null) return null;
    return Math.max(0, Math.round((outMs.value - inMs.value) / 1000));
});

const formatDuration = (seconds) => {
    if (seconds === null) return '--:--';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0
        ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        : `${m}:${String(s).padStart(2, '0')}`;
};

const formatClock = (ms) =>
    ms === null ? '--' : new Date(ms).toISOString().replace('T', ' ').slice(0, 19) + 'Z';

/** Snap to the segment grid, measured from the start of the archive window. */
const snap = (ms) => {
    if (!bounds.value) return ms;
    const grid = props.segmentSeconds * 1000;
    return bounds.value.from + Math.round((ms - bounds.value.from) / grid) * grid;
};

const clamp = (ms) => {
    if (!bounds.value) return ms;
    return Math.min(bounds.value.to, Math.max(bounds.value.from, ms));
};

const setMarker = (which, ms) => {
    const value = new Date(snap(clamp(ms))).toISOString();
    emit(which === 'in' ? 'update:startsAt' : 'update:endsAt', value);
};

const msFromEvent = (event) => {
    if (!bounds.value) return null;
    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    return bounds.value.from + ratio * bounds.value.span;
};

const scrubTo = (event) => {
    const ms = msFromEvent(event);
    if (ms === null) return;
    playhead.value = ms;

    // The preview plays the current cut, so seeking is relative to the in marker.
    if (video.value && inMs.value !== null) {
        const offset = (ms - inMs.value) / 1000;
        if (offset >= 0 && Number.isFinite(video.value.duration)) {
            video.value.currentTime = Math.min(offset, video.value.duration);
        }
    }
};

const markHere = (which) => setMarker(which, playhead.value || inMs.value || bounds.value?.from);

const nudge = (which, segments) => {
    const current = which === 'in' ? inMs.value : outMs.value;
    if (current === null) return;
    setMarker(which, current + segments * props.segmentSeconds * 1000);
};

// Keep the playhead inside the archive when the bounds arrive after mount.
watch(bounds, (value) => {
    if (value && (playhead.value < value.from || playhead.value > value.to)) {
        playhead.value = inMs.value ?? value.from;
    }
}, { immediate: true });

const playheadPct = computed(() => pct(playhead.value));
</script>

<template>
    <div class="space-y-4">
        <div v-if="!bounds" class="rounded border border-hairline bg-surface-2 p-4 text-sm text-fg-3">
            No archive is available for this source yet, so there is nothing to cut. Segments
            appear here a few seconds behind live.
        </div>

        <template v-else>
            <div class="overflow-hidden rounded border border-hairline bg-black">
                <video
                    v-if="previewUrl"
                    ref="video"
                    :src="previewUrl"
                    controls
                    class="aspect-video w-full"
                ></video>
                <div v-else class="flex aspect-video w-full items-center justify-center text-sm text-fg-3">
                    Save the cut to generate a preview.
                </div>
            </div>

            <!-- The scrubber spans the whole archive window, not the cut, so an operator
                 can see how much material sits outside the current markers. -->
            <div>
                <div
                    class="relative h-12 w-full cursor-pointer rounded border border-hairline bg-surface-2"
                    @click="scrubTo"
                >
                    <div class="absolute inset-y-0 bg-primary-500/25" :style="selectionStyle"></div>

                    <div
                        v-if="inPct !== null"
                        class="absolute inset-y-0 w-0.5 bg-primary-500"
                        :style="{ left: `${inPct}%` }"
                    ></div>
                    <div
                        v-if="outPct !== null"
                        class="absolute inset-y-0 w-0.5 bg-primary-500"
                        :style="{ left: `${outPct}%` }"
                    ></div>
                    <div
                        v-if="playheadPct !== null"
                        class="absolute inset-y-0 w-px bg-fg-1"
                        :style="{ left: `${playheadPct}%` }"
                    ></div>
                </div>

                <div class="mt-1 flex justify-between text-[11px] text-fg-3">
                    <span>{{ formatClock(bounds.from) }}</span>
                    <span>archive available</span>
                    <span>{{ formatClock(bounds.to) }}</span>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded border border-hairline px-2 py-1 text-xs" @click="markHere('in')">
                            Set in here
                        </button>
                        <button type="button" class="rounded border border-hairline px-2 py-1 text-xs" @click="nudge('in', -1)">-2s</button>
                        <button type="button" class="rounded border border-hairline px-2 py-1 text-xs" @click="nudge('in', 1)">+2s</button>
                    </div>
                    <p class="font-mono text-xs text-fg-2">in &nbsp;{{ formatClock(inMs) }}</p>
                </div>

                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded border border-hairline px-2 py-1 text-xs" @click="markHere('out')">
                            Set out here
                        </button>
                        <button type="button" class="rounded border border-hairline px-2 py-1 text-xs" @click="nudge('out', -1)">-2s</button>
                        <button type="button" class="rounded border border-hairline px-2 py-1 text-xs" @click="nudge('out', 1)">+2s</button>
                    </div>
                    <p class="font-mono text-xs text-fg-2">out {{ formatClock(outMs) }}</p>
                </div>
            </div>

            <p class="text-xs text-fg-3">
                Cut length {{ formatDuration(duration) }}. Markers snap to {{ segmentSeconds }}s
                because segments are never split; saving rewrites the playlist, so this can be
                adjusted again at any time.
            </p>
        </template>
    </div>
</template>
