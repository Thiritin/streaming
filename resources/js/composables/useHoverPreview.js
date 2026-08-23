import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

/*
 * Hover preview for an archive tile.
 *
 * There is one still per recording and no storyboard sprite, so the only thing
 * left to preview from is the recording's own playlist. The tile attaches to it
 * muted, pinned to the lowest rendition, a little way in - close enough to
 * YouTube's hover preview, without a job that cuts sprites for every recording
 * in the archive.
 *
 * Exactly one tile previews at a time, process-wide. Dragging a pointer across a
 * grid would otherwise leave a trail of players fetching segments off the edge,
 * which is the one thing this must not do.
 *
 * Once it is playing the tile is scrubbable: the cursor's x position picks one of
 * a handful of chunks and the preview jumps there. Chunks rather than a free seek
 * because every distinct position is a segment fetched off the edge, and a cursor
 * swept across a tile would ask for the whole hour.
 */

let activeTeardown = null;

const stopActive = () => {
    if (activeTeardown) {
        const teardown = activeTeardown;
        activeTeardown = null;
        teardown();
    }
};

/**
 * Hovering is only meaningful with a real pointer, and pulling video is only
 * polite on a connection that has not asked us not to.
 */
const previewAllowed = () => {
    if (typeof window === 'undefined') return false;
    if (window.matchMedia('(hover: none)').matches) return false;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;

    const connection = navigator.connection;
    if (connection?.saveData) return false;
    if (connection?.effectiveType && /(^|-)2g$/.test(connection.effectiveType)) return false;

    return true;
};

// Hover has to look deliberate before it costs a segment request.
const HOVER_DELAY = 700;

// Long enough to tell what a recording is, short enough that a tile left under
// an idle cursor does not stream indefinitely.
const MAX_PREVIEW = 20000;

// How many chunks a tile is divided into for scrubbing. Anything shorter than a
// couple of minutes is one chunk: the segments would be closer together than the
// cursor can be aimed.
const CHUNKS = 6;
const CHUNKED_ABOVE = 120;

export function useHoverPreview(getUrl) {
    const mounted = ref(false);
    const playing = ref(false);
    const video = ref(null);
    const duration = ref(0);
    const time = ref(0);

    let hoverTimer = null;
    let stopTimer = null;
    let hls = null;
    let seekedChunk = 0;

    // How far through the recording the preview currently is, 0 to 1.
    const fraction = computed(() =>
        duration.value > 0 ? Math.min(1, Math.max(0, time.value / duration.value)) : 0
    );

    const chunks = computed(() => (duration.value > CHUNKED_ABOVE ? CHUNKS : 1));

    // Filled part of one chunk of the scrub bar, 0 to 1.
    const chunkFill = (index) => Math.min(1, Math.max(0, fraction.value * chunks.value - index));

    const teardown = () => {
        clearTimeout(hoverTimer);
        clearTimeout(stopTimer);
        hoverTimer = null;
        stopTimer = null;

        if (hls) {
            hls.destroy();
            hls = null;
        }

        if (video.value) {
            video.value.pause();
            video.value.removeAttribute('src');
            video.value.load();
        }

        playing.value = false;
        mounted.value = false;
        duration.value = 0;
        time.value = 0;
        seekedChunk = 0;
    };

    /**
     * Move the preview to the chunk under the cursor. A repeat of the chunk the
     * preview is already in is dropped rather than re-seeking, so sweeping a
     * cursor along a tile costs one fetch per chunk crossed and no more.
     */
    const scrubTo = (position) => {
        const element = video.value;

        if (!playing.value || !element || !duration.value || chunks.value === 1) return;

        const count = chunks.value;
        const index = Math.min(count - 1, Math.max(0, Math.floor(position * count)));

        if (index === seekedChunk) return;

        seekedChunk = index;

        const target = (duration.value * index) / count + 0.5;

        element.currentTime = target;

        /*
         * hls.js is told where the playhead went as well.
         *
         * The preview holds a few seconds of buffer by design, so every scrub lands
         * outside it. Left to the seek alone, the loader keeps feeding the range it
         * was already on, nothing arrives at the new position and the element falls
         * back to what is buffered - which reads as a scrub that does nothing.
         */
        hls?.startLoad(target);

        // A tile being scrubbed is being looked at, so the idle stop starts again.
        clearTimeout(stopTimer);
        stopTimer = setTimeout(teardown, MAX_PREVIEW);
    };

    const attach = async () => {
        const url = getUrl();
        if (!url) return;

        mounted.value = true;
        await nextTick();

        const element = video.value;
        if (!element) return;

        element.muted = true;
        element.playsInline = true;

        // Read off the element rather than trusting one event: with MSE the duration
        // arrives as its own durationchange, and on a rendition switch it can arrive
        // again. A preview that has not learnt its length cannot be scrubbed.
        const sync = () => {
            duration.value = Number.isFinite(element.duration) ? element.duration : 0;
            time.value = element.currentTime;

            if (duration.value > 0) {
                seekedChunk = Math.min(
                    chunks.value - 1,
                    Math.floor((element.currentTime / duration.value) * chunks.value)
                );
            }
        };

        element.addEventListener('durationchange', sync);
        element.addEventListener('loadedmetadata', sync);
        element.addEventListener('timeupdate', sync);

        const seekIn = () => {
            // A few percent in, past the title card and the empty stage before
            // anyone walks on.
            if (element.duration && Number.isFinite(element.duration)) {
                element.currentTime = element.duration * 0.08;
            }
        };

        const play = () => {
            element
                .play()
                .then(() => {
                    playing.value = true;
                })
                .catch(() => teardown());
        };

        if (element.canPlayType('application/vnd.apple.mpegurl')) {
            element.src = url;
            element.addEventListener('loadedmetadata', seekIn, { once: true });
            element.addEventListener('canplay', play, { once: true });
        } else {
            const { default: Hls } = await import('hls.js');

            if (!Hls.isSupported() || !mounted.value) {
                teardown();
                return;
            }

            hls = new Hls({
                // Lowest rendition, and keep it there: a preview is a thumbnail
                // that moves, not a viewing.
                startLevel: 0,
                capLevelToPlayerSize: true,
                maxBufferLength: 6,
                maxMaxBufferLength: 10,
                backBufferLength: 0,
            });

            hls.on(Hls.Events.MANIFEST_PARSED, () => {
                hls.autoLevelCapping = 0;
                seekIn();
                play();
            });

            hls.on(Hls.Events.ERROR, (_event, data) => {
                if (data.fatal) teardown();
            });

            hls.loadSource(url);
            hls.attachMedia(element);
        }

        stopTimer = setTimeout(teardown, MAX_PREVIEW);
    };

    const enter = () => {
        if (!previewAllowed() || !getUrl()) return;

        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => {
            stopActive();
            activeTeardown = teardown;
            attach();
        }, HOVER_DELAY);
    };

    const leave = () => {
        if (activeTeardown === teardown) {
            activeTeardown = null;
        }

        teardown();
    };

    onBeforeUnmount(leave);

    return { mounted, playing, video, enter, leave, scrubTo, chunks, chunkFill, fraction, time };
}
