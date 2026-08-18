<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { PawPrint } from 'lucide-vue-next';

/**
 * The boop button under the player. Click it as often as you like; the number
 * is the whole room's, not yours.
 *
 * Clicks are counted locally and posted in batches, and the server broadcasts
 * at most once a second per show, so mashing costs one request per flush
 * instead of one per click.
 */
const props = defineProps({
    showId: {
        type: [Number, String],
        required: true,
    },
    showSlug: {
        type: String,
        required: true,
    },
    initialCount: {
        type: Number,
        default: 0,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const FLUSH_DELAY = 700;
const MAX_PER_REQUEST = 50;
const MAX_PAWS_FROM_OTHERS = 6;

const total = ref(props.initialCount);
const paws = ref([]);
const isPopping = ref(false);
const isTicking = ref(false);

let pending = 0;
let inFlight = 0;
let flushTimer = null;
let popTimer = null;
let tickTimer = null;
let pawId = 0;
let channel = null;
let boopListener = null;

// Every digit, always. An abbreviated count is the one thing this button must
// not do: at "20k" a click changes nothing on screen and the boop feels ignored.
const numbers = new Intl.NumberFormat();
const formatted = computed(() => numbers.format(total.value));

const spawnPaws = (count, mine = false) => {
    for (let i = 0; i < count; i++) {
        const id = ++pawId;

        paws.value.push({
            id,
            mine,
            style: {
                '--boop-drift': `${Math.round((Math.random() - 0.5) * 60)}px`,
                '--boop-tilt': `${Math.round((Math.random() - 0.5) * 50)}deg`,
                '--boop-scale': (0.7 + Math.random() * 0.6).toFixed(2),
                '--boop-duration': `${(1.1 + Math.random() * 0.7).toFixed(2)}s`,
                left: `${40 + Math.random() * 20}%`,
            },
        });
    }

    // Cheaper than one timer per paw, and the list never grows unbounded.
    if (paws.value.length > 60) {
        paws.value.splice(0, paws.value.length - 60);
    }
};

const removePaw = (id) => {
    const index = paws.value.findIndex((paw) => paw.id === id);

    if (index !== -1) paws.value.splice(index, 1);
};

const flush = async () => {
    flushTimer = null;

    if (pending === 0 || props.disabled) return;

    const sending = Math.min(pending, MAX_PER_REQUEST);
    pending -= sending;
    inFlight += sending;

    try {
        const { data } = await window.axios.post(route('show.boop', props.showSlug), {
            count: sending,
        });

        // The server total already includes this batch; anything clicked while the
        // request was in the air is still only counted locally, so add it back.
        total.value = Math.max(total.value, data.total + pending);
    } catch (error) {
        // A rejected batch (rate limit, show no longer live) just does not count.
        total.value = Math.max(0, total.value - sending);
    } finally {
        inFlight -= sending;

        if (pending > 0 && !flushTimer) {
            flushTimer = setTimeout(flush, FLUSH_DELAY);
        }
    }
};

const boop = () => {
    if (props.disabled) return;

    total.value += 1;
    pending += 1;

    spawnPaws(1, true);

    isPopping.value = true;
    clearTimeout(popTimer);
    popTimer = setTimeout(() => (isPopping.value = false), 200);

    if (!flushTimer) {
        flushTimer = setTimeout(flush, FLUSH_DELAY);
    }
};

const subscribe = () => {
    if (!props.showId || !window.Echo) return;

    channel = window.Echo.channel(`show.${props.showId}`);
    boopListener = (event) => {
        if (Number(event.show_id) !== Number(props.showId)) return;

        // Our own un-acknowledged clicks are on top of the server's number.
        total.value = Math.max(total.value, event.total + pending + inFlight);
        spawnPaws(Math.min(event.delta, MAX_PAWS_FROM_OTHERS));
    };

    channel.listen('.show.booped', boopListener);
};

const unsubscribe = () => {
    // stopListening rather than leave: the player page shares this channel for
    // show status events and would lose them.
    if (channel && boopListener) channel.stopListening('.show.booped', boopListener);

    channel = null;
    boopListener = null;
};

// The digits flash on any change, ours or the room's, so a number that moves on
// its own still reads as movement.
watch(total, () => {
    isTicking.value = true;
    clearTimeout(tickTimer);
    tickTimer = setTimeout(() => (isTicking.value = false), 260);
});

watch(() => props.initialCount, (value) => {
    total.value = Math.max(total.value, value ?? 0);
});

watch(() => props.showId, () => {
    unsubscribe();
    subscribe();
});

onMounted(subscribe);

onBeforeUnmount(() => {
    clearTimeout(flushTimer);
    clearTimeout(popTimer);
    clearTimeout(tickTimer);
    flushTimer = null;
    unsubscribe();
    flush();
});
</script>

<template>
    <!-- z-20: the player above this bar is `z-10 relative`, and paws rising out of
         the button would otherwise disappear behind the video. -->
    <div class="relative z-20 shrink-0">
        <div class="pointer-events-none absolute bottom-full left-0 right-0 h-32 overflow-visible">
            <span
                v-for="paw in paws"
                :key="paw.id"
                class="boop-paw absolute bottom-0"
                :style="paw.style"
                @animationend="removePaw(paw.id)"
            >
                <PawPrint
                    class="w-5 h-5 drop-shadow"
                    :class="paw.mine ? 'text-rose-300' : 'text-rose-500'"
                />
            </span>
        </div>

        <button
            type="button"
            :disabled="disabled"
            @click="boop"
            class="inline-flex items-center gap-1.5 px-3 py-1 text-sm rounded transition-colors select-none disabled:opacity-50 disabled:cursor-not-allowed bg-primary-800 text-primary-300 hover:bg-primary-700 hover:text-rose-300"
            :title="disabled ? 'Boops open while the show is live' : 'Boop! Click as often as you like'"
        >
            <PawPrint
                class="w-4 h-4 transition-transform duration-150"
                :class="isPopping ? 'scale-125 -rotate-12 text-rose-400' : 'scale-100'"
            />
            <span
                class="tabular-nums transition-colors duration-200"
                :class="isTicking ? 'text-rose-300' : ''"
            >{{ formatted }}</span>
            <span class="hidden md:inline">Boops</span>
        </button>
    </div>
</template>

<style scoped>
.boop-paw {
    animation: boop-rise var(--boop-duration, 1.4s) ease-out forwards;
}

@keyframes boop-rise {
    0% {
        opacity: 0;
        transform: translateY(0) translateX(0) scale(0.4) rotate(0deg);
    }
    15% {
        opacity: 1;
        transform: translateY(-10px) translateX(0) scale(var(--boop-scale, 1)) rotate(0deg);
    }
    100% {
        opacity: 0;
        transform: translateY(-7rem) translateX(var(--boop-drift, 0)) scale(var(--boop-scale, 1)) rotate(var(--boop-tilt, 0deg));
    }
}

@media (prefers-reduced-motion: reduce) {
    .boop-paw {
        animation-duration: 0.4s;
    }

    @keyframes boop-rise {
        0% { opacity: 0.8; transform: none; }
        100% { opacity: 0; transform: none; }
    }
}
</style>
