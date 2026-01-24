<script setup>
import { Head } from '@inertiajs/vue3';
import ChatBox from "@/Components/Livestream/ChatBox.vue";

// Props
const props = defineProps({
    show: {
        type: Object,
        required: true,
    },
    chatMessages: {
        type: Array,
        required: false
    },
    rateLimit: {
        type: Object,
        required: false
    },
    sourceId: {
        type: [Number, String],
        required: true
    }
});
</script>

<template>
    <div class="h-screen bg-primary-900 flex flex-col">
        <Head>
            <title>Chat - {{ show.title }}</title>
        </Head>

        <!-- Header -->
        <div class="bg-primary-950 border-b border-primary-800 px-4 py-3 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <span class="text-white font-semibold">{{ show.title }}</span>
            </div>
            <span v-if="show.status === 'live'" class="bg-red-600 text-white px-2 py-0.5 rounded text-xs font-bold uppercase">
                LIVE
            </span>
        </div>

        <!-- Chat -->
        <div class="flex-1 overflow-hidden">
            <ChatBox
                :rate-limit="rateLimit"
                :chat-messages="chatMessages"
                :source-id="sourceId"
                class="h-full"
            />
        </div>
    </div>
</template>
