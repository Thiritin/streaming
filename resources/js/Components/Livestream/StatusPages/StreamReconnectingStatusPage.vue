<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import FaArrowPathIcon from "@/Components/Icons/FaArrowPathIcon.vue";

const dots = ref('');
let dotsInterval = null;

onMounted(() => {
    // Animate dots for loading effect
    dotsInterval = setInterval(() => {
        if (dots.value.length >= 3) {
            dots.value = '';
        } else {
            dots.value += '.';
        }
    }, 500);
});

onUnmounted(() => {
    if (dotsInterval) {
        clearInterval(dotsInterval);
    }
});
</script>

<template>
    <div class="flex h-full items-center justify-center bg-black rounded-lg min-h-[80vh]">
        <div class="text-center p-8">
            <!-- Spinning reconnect icon -->
            <div class="relative mb-8">
                <FaArrowPathIcon class="fill-current text-primary-400 text-[6rem] block mx-auto animate-spin-slow"></FaArrowPathIcon>
                
                <!-- Pulsing glow effect -->
                <div class="absolute inset-0 flex items-center justify-center">
                    <div class="w-32 h-32 bg-primary-500 rounded-full opacity-20 animate-ping"></div>
                </div>
            </div>
            
            <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                Reconnecting to stream{{ dots }}
            </h2>
            
            <p class="text-primary-300 text-lg mb-6 max-w-md mx-auto">
                Please wait a few seconds while we establish the connection
            </p>
            
            <!-- Progress indicator -->
            <div class="flex justify-center items-center space-x-2 mb-6">
                <div class="w-2 h-2 bg-primary-500 rounded-full animate-bounce" style="animation-delay: 0ms;"></div>
                <div class="w-2 h-2 bg-primary-500 rounded-full animate-bounce" style="animation-delay: 150ms;"></div>
                <div class="w-2 h-2 bg-primary-500 rounded-full animate-bounce" style="animation-delay: 300ms;"></div>
            </div>
            
            <p class="text-primary-500 text-sm">
                This usually takes 5-10 seconds
            </p>
        </div>
    </div>
</template>

<style scoped>
@keyframes spin-slow {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.animate-spin-slow {
    animation: spin-slow 2s linear infinite;
}

@keyframes bounce {
    0%, 100% {
        transform: translateY(0);
        opacity: 1;
    }
    50% {
        transform: translateY(-10px);
        opacity: 0.7;
    }
}

.animate-bounce {
    animation: bounce 1.5s ease-in-out infinite;
}
</style>