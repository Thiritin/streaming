import { computed, toRef } from 'vue';

/**
 * Where to send a viewer whose show is not watchable.
 *
 * The choice is made server side by StreamController::resolvePromotedShow(): the featured
 * channel if it is live, otherwise the busiest live show, otherwise what is on next. This
 * only turns that into a link and a label, shared by the ended, cancelled and scheduled
 * status pages so the three cannot drift apart.
 *
 * @param {object} props component props containing `promoted` and a `fallbackUrl`
 */
export function usePromotedShow(props, fallbackUrl = '/') {
    const promoted = toRef(props, 'promoted');

    const promotedUrl = computed(() =>
        promoted.value?.slug ? `/show/${promoted.value.slug}` : fallbackUrl,
    );

    const promotedLabel = computed(() => {
        const show = promoted.value;
        if (!show) return null;

        if (show.is_live) {
            // Naming the channel reads better than the title when it is the one people
            // already think of as "the stream".
            return show.is_primary_channel
                ? `Watch ${show.source} now`
                : `Watch ${show.title} now`;
        }

        return `Up next: ${show.title}`;
    });

    const hasPromoted = computed(() => Boolean(promoted.value));

    return { promoted, promotedUrl, promotedLabel, hasPromoted };
}
