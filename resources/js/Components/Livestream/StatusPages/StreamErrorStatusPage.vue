<script setup>
import { Link } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
</script>

<template>
    <div class="flex h-full items-center justify-center bg-black rounded-lg min-h-[80vh]">
        <div class="text-center p-8">
            <AlertTriangle :size="96" class="text-yellow-500 block mx-auto mb-8" />
            
            <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-white mb-4">
                Connection Interrupted
            </h2>
            
            <p class="text-primary-300 text-lg mb-6 max-w-md mx-auto">
                We're experiencing a temporary disconnect. Our team is working on reconnecting your stream.
            </p>
            
            <div class="flex items-center justify-center space-x-2 mb-8">
                <div class="animate-pulse">
                    <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                </div>
                <span class="text-primary-400">Attempting to reconnect...</span>
            </div>
            
            <Link
                :href="route('shows.index')"
                class="inline-block px-6 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors font-semibold"
            >
                Browse Other Shows
            </Link>
        </div>
    </div>
</template>

<style scoped>
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>