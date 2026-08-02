import { onUnmounted, ref } from 'vue'

/**
 * A shared, ticking clock.
 *
 * Every caller gets the same ref, so a page full of countdowns costs one interval
 * instead of one per component. The interval stops once nobody is listening.
 */
const now = ref(new Date())

let timer = null
let subscribers = 0

export function useNow(intervalMs = 1000) {
    subscribers += 1

    if (timer === null) {
        timer = setInterval(() => {
            now.value = new Date()
        }, intervalMs)
    }

    onUnmounted(() => {
        subscribers -= 1

        if (subscribers <= 0 && timer !== null) {
            clearInterval(timer)
            timer = null
            subscribers = 0
        }
    })

    return now
}
