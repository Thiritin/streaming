import { router } from '@inertiajs/vue3';

// Longest a page may sit under a captured snapshot before the transition is
// released un-animated. While a view transition is pending the browser suppresses
// painting, so a slow visit would otherwise look hung: the progress bar itself is
// part of the frozen snapshot and would stop moving. Tiles prefetch on hover, so
// in practice the response is already cached and this never fires.
const MAX_FREEZE_MS = 600;

let releasePending = null;
let releaseTimer = null;

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function supportsViewTransitions() {
    return typeof document.startViewTransition === 'function' && !prefersReducedMotion();
}

// Let the browser take its "after" snapshot and animate. Safe to call twice.
function release() {
    if (releaseTimer) {
        clearTimeout(releaseTimer);
        releaseTimer = null;
    }

    const resolve = releasePending;
    releasePending = null;
    resolve?.();
}

export function installViewTransitions() {
    router.on('before', (event) => {
        // A visit fired while one is still animating: let the first one land
        // rather than stacking two transitions on the same document.
        if (releasePending) {
            release();
            return;
        }

        if (!supportsViewTransitions()) return;

        // Only whole-page navigations get a transition.
        //
        // A POST that re-renders the same form has nothing to morph. Neither does
        // a partial or preserve-state visit, and those are the ones that must not
        // freeze: the manage tables re-query on every filter and keystroke
        // (Components/Manage/useTableQuery.js), and a snapshot over the page each
        // time would make typing feel broken.
        const visit = event.detail?.visit;
        if (!visit) return;
        if (String(visit.method ?? 'get').toLowerCase() !== 'get') return;
        if (visit.preserveState) return;
        if (visit.only?.length || visit.except?.length) return;

        // Inertia has no "about to swap" hook, so the transition is held open
        // across the request and closed on `finish`, by which point the new page
        // is in the DOM. The DOM still mutates normally while held; only painting
        // is suppressed.
        document.startViewTransition(
            () =>
                new Promise((resolve) => {
                    releasePending = resolve;
                    releaseTimer = setTimeout(release, MAX_FREEZE_MS);
                })
        );
    });

    router.on('finish', release);
}
