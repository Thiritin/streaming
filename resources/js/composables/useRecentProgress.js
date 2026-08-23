/*
 * What the player last reported, kept on the client for as long as the tab lives.
 *
 * The archive page is rendered by the server and then cached by Inertia's history,
 * so coming back from a recording - with the browser's back button, or into a
 * prefetched page - redraws the grid from props fetched before the viewer watched
 * anything. The bar under the tile then disagrees with the bar under the player
 * they were just looking at.
 *
 * This is a freshness cache and not a second source of truth: an entry is used only
 * where it is newer than the row the server sent, and the server's own number wins
 * the moment it catches up. Session storage rather than local: it exists to survive
 * a navigation, not a browser.
 */
const KEY = 'archive:recent-progress';

const read = () => {
    try {
        return JSON.parse(window.sessionStorage.getItem(KEY) ?? '{}');
    } catch {
        return {};
    }
};

const write = (entries) => {
    try {
        window.sessionStorage.setItem(KEY, JSON.stringify(entries));
    } catch {
        // A browser refusing storage costs freshness, nothing more.
    }
};

/** Called by the player every time it reports a position to the server. */
export const rememberProgress = (id, { position, duration, completed = false }) => {
    if (typeof window === 'undefined' || !id || !(position > 0)) return;

    const entries = read();

    entries[id] = {
        position: Math.floor(position),
        duration: duration ? Math.round(duration) : null,
        completed,
        at: Math.floor(Date.now() / 1000),
    };

    write(entries);
};

/**
 * The progress to draw for one recording: whichever of the server's row and this
 * tab's memory was written last.
 */
export const effectiveProgress = (id, progress) => {
    if (typeof window === 'undefined') return progress ?? null;

    const local = read()[id];

    if (!local) return progress ?? null;
    if (progress?.updated_at && progress.updated_at >= local.at) return progress;

    const duration = local.duration ?? progress?.duration ?? null;

    return {
        position: local.position,
        duration,
        completed: local.completed,
        fraction: duration ? Math.min(1, Math.max(0, local.position / duration)) : (progress?.fraction ?? 0),
        updated_at: local.at,
    };
};
