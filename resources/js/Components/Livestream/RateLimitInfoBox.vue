<script setup>
import {defineProps, defineEmits, ref, watch, onMounted} from "vue";

const props = defineProps(['rateLimit'])
const secondsLeft = ref(props.rateLimit.secondsLeft);
const emit = defineEmits(['rateLimitGuard']);

let timerId = null;

onMounted(() => {
    if (props.rateLimit.secondsLeft > 0) {
        startCountdown();
    }
});

watch(() => props.rateLimit.secondsLeft, (newValue) => {
    secondsLeft.value = newValue;
    if (newValue > 0) {
        emit('rateLimitGuard', true);
    } else {
        emit('rateLimitGuard', false);
    }
    if (timerId !== null) {
        clearInterval(timerId);
    }
    startCountdown();
});
const startCountdown = () => {
    timerId = setInterval(() => {
        if (secondsLeft.value > 0) {
            secondsLeft.value--;
            props.rateLimit.secondsLeft = secondsLeft.value;
        } else {
            emit('rateLimitGuard', false)
            clearInterval(timerId)
            timerId = null;
        }
    }, 1000);
};

</script>

<template>
    <div>
        <div v-if="props.rateLimit.slowMode" class="text-center text-primary-400 text-sm pb-3 ">
            Chat is in slow mode, you can send <span v-if="secondsLeft > 0">your next message in {{ secondsLeft }} seconds</span><span
            v-else> a message every {{ Math.round(props.rateLimit.rateDecay) }} second(s)</span>.
        </div>
        <div v-else-if="secondsLeft > 0" class="text-center text-red-400 text-sm pb-3">
            You are sending messages too fast. Try again in {{ secondsLeft }} seconds.
        </div>
    </div>
</template>

<style scoped>

</style>
