import { supportsViewTransitions } from '@/viewTransitions';

// A `view-transition-name` has to be unique in the document, so exactly one
// element may wear this at a time. A browse grid has forty thumbnails that could
// all plausibly be the origin of the morph, and a player page renders both a
// player and a sidebar of tiles, so ownership is tracked here in one place:
// claiming always takes the name off whoever held it last.
const MEDIA_HERO = 'media-hero';

let holder = null;

export function claimMediaHero(el) {
    if (!supportsViewTransitions()) return;
    if (holder === el) return;

    if (holder) holder.style.viewTransitionName = '';

    holder = el ?? null;

    if (holder) holder.style.viewTransitionName = MEDIA_HERO;
}

// The landing half of the morph: a player claims the name for as long as it is on
// screen. `updated` re-asserts it because Inertia reuses the player component
// between two show pages, so `mounted` fires only once, and because a tile in the
// same page's sidebar takes the name away when it is clicked.
export const mediaHeroDirective = {
    mounted: (el) => claimMediaHero(el),
    updated: (el) => claimMediaHero(el),
    unmounted: (el) => {
        if (holder === el) {
            el.style.viewTransitionName = '';
            holder = null;
        }
    },
};
