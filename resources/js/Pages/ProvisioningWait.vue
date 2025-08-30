<template>
    <div class="min-h-screen bg-primary-950 flex items-center justify-center px-4">
        <Container>
            <div class="max-w-2xl mx-auto">
                <div class="bg-primary-900 rounded-lg shadow-xl p-8 text-center">
                    <!-- Animated loading spinner -->
                    <div class="mb-6 flex justify-center">
                        <div class="relative">
                            <div class="w-24 h-24 border-8 border-primary-700 rounded-full"></div>
                            <div class="absolute top-0 left-0 w-24 h-24 border-8 border-primary-500 rounded-full border-t-transparent animate-spin"></div>
                        </div>
                    </div>
                    
                    <h1 class="text-3xl font-bold text-primary-100 mb-4">
                        Setting Up Your Stream Access
                    </h1>
                    
                    <p class="text-lg text-primary-300 mb-8">
                        We're preparing a streaming server for you. This usually takes just a moment.
                    </p>
                    
                    <!-- Queue information -->
                    <div class="bg-primary-800 rounded-lg p-6 mb-8">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-primary-400 text-sm mb-1">Your Position</p>
                                <p class="text-3xl font-bold text-primary-100">{{ queuePosition }}</p>
                            </div>
                            <div>
                                <p class="text-primary-400 text-sm mb-1">Total Waiting</p>
                                <p class="text-3xl font-bold text-primary-100">{{ totalWaiting }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Status message -->
                    <div class="text-primary-300">
                        <p v-if="!isProvisioning" class="mb-2">
                            <span class="inline-block w-2 h-2 bg-yellow-500 rounded-full mr-2"></span>
                            Waiting for available server...
                        </p>
                        <p v-else class="mb-2">
                            <span class="inline-block w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                            Server provisioning in progress...
                        </p>
                        <p class="text-sm text-primary-400 mt-4">
                            You'll be automatically redirected once your access is ready.
                        </p>
                    </div>
                    
                    <!-- Auto-refresh notice -->
                    <div class="mt-8 text-xs text-primary-500">
                        This page automatically checks for updates every few seconds
                    </div>
                </div>
            </div>
        </Container>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Container from '@/Components/Container.vue';

const props = defineProps({
    queuePosition: Number,
    totalWaiting: Number,
    userId: Number,
    isProvisioning: Boolean,
});

let channel = null;
let checkInterval = null;

onMounted(() => {
    // Listen for server assignment via WebSocket
    channel = window.Echo.private(`User.${props.userId}.StreamUrl`)
        .listen('.server.assignment.changed', (e) => {
            console.log('Server assignment update:', e);
            // Redirect to shows page when server is assigned
            if (e.hasAssignment && e.hlsUrls) {
                router.visit(route('shows.grid'));
            }
        });
    
    // Also poll every 5 seconds as fallback
    checkInterval = setInterval(() => {
        router.reload({ 
            only: ['queuePosition', 'totalWaiting', 'isProvisioning'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 5000);
});

onUnmounted(() => {
    if (channel) {
        window.Echo.leave(`User.${props.userId}.StreamUrl`);
    }
    if (checkInterval) {
        clearInterval(checkInterval);
    }
});
</script>