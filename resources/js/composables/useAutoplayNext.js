import { computed, onUnmounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Roll a viewer on to the next show on their own.
 *
 * A show that has ended, been cancelled or has not started is a dead end: the page
 * works, there is simply nothing on it. The server already picked where to send
 * people next (StreamController::resolvePromotedShow, featured channel first), so
 * this counts that visit down out loud and then makes it.
 *
 * Only a live target ever auto-plays. Sending somebody to a show that starts in four
 * hours is not a continuation, it is a second dead end, so a scheduled target keeps
 * the card and loses the countdown.
 *
 * The countdown holds while the tab is hidden. Navigating a tab nobody is looking at
 * loses their place in the show they came back for.
 *
 * @param {import('vue').Ref<object|null>} target the promoted show
 * @param {{seconds?: number, enabled?: boolean}} options
 */
export function useAutoplayNext(target, { seconds = 12, enabled = true } = {}) {
    const remaining = ref(seconds)
    const cancelled = ref(false)
    let timer = null

    const eligible = computed(
        () => enabled && Boolean(target.value?.slug) && Boolean(target.value?.is_live) && target.value?.can_watch !== false,
    )

    const counting = computed(() => eligible.value && !cancelled.value)

    // 0 at the start, 1 when it fires. Drives the progress bar.
    const progress = computed(() => (seconds - remaining.value) / seconds)

    const stop = () => {
        if (timer !== null) {
            clearInterval(timer)
            timer = null
        }
    }

    const go = () => {
        stop()
        if (target.value?.slug) {
            router.visit(`/show/${target.value.slug}`)
        }
    }

    const cancel = () => {
        cancelled.value = true
        stop()
    }

    const start = () => {
        if (!counting.value || timer !== null || document.hidden) return

        timer = setInterval(() => {
            remaining.value -= 1

            if (remaining.value <= 0) {
                go()
            }
        }, 1000)
    }

    const onVisibility = () => (document.hidden ? stop() : start())

    watch(counting, (on) => (on ? start() : stop()), { immediate: true })

    document.addEventListener('visibilitychange', onVisibility)

    onUnmounted(() => {
        stop()
        document.removeEventListener('visibilitychange', onVisibility)
    })

    return { remaining, progress, counting, cancelled, cancel, go }
}
