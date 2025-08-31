<script setup>
import ChatMessageBox from "@/Components/Livestream/ChatMessageBox.vue";
import { ref, provide, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

// Provide axios for the component
provide('axios', axios);

const props = defineProps({
    chatCommands: Array,
    rateLimit: Object
});

const message = ref('');
const error = ref('');

// Set up page props before component initialization
const page = usePage();

// Set chat data immediately
if (!page.props.chat) {
    page.props.chat = {};
}
page.props.chat.commands = props.chatCommands || [];
page.props.chat.config = {
    maxMessageLength: 500,
    allowedDomains: ['eurofurence.org']
};
page.props.chat.emotes = {
    available: {},
    global: [],
    favorites: []
};

if (!page.props.auth) {
    page.props.auth = {
        user: {
            name: 'TestUser',
            role: null,
            badge: null
        }
    };
}

console.log('Page props initialized:', page.props.chat.commands);

function sendMessage() {
    console.log('Sending message:', message.value);
    message.value = '';
}
</script>

<template>
    <div class="min-h-screen bg-primary-900 p-8">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-white text-2xl mb-4">Chat Command Test</h1>
            <div class="mb-4 text-white">
                <p>Test the command autocomplete by typing:</p>
                <ul class="list-disc ml-5 mt-2">
                    <li>/ - Should show all commands</li>
                    <li>/t - Should show timeout command</li>
                    <li>/slow - Should show slowmode command</li>
                    <li>Press Enter to send a command</li>
                </ul>
            </div>
            <ChatMessageBox 
                v-model="message"
                :rate-limit="rateLimit || { secondsLeft: 0 }"
                :error="error"
                @send-message="sendMessage"
            />
            <div class="mt-4 text-white">
                <p>Current message: {{ message }}</p>
            </div>
        </div>
    </div>
</template>