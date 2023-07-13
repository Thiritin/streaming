<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from "@/Components/Logo.vue";
import LoginScreenWelcome from "@/Components/LoginScreenWelcome.vue";
</script>

<template>
    <GuestLayout>
        <Logo></Logo>
        <LoginScreenWelcome title="Livestream" sub-title="Only for attendees" class="mb-4"></LoginScreenWelcome>
        <p class="text-primary-300 text-sm mb-3">Please note that a valid registration is required to access the Eurofurence livestream.</p>
        <a
                class='py-3 mt-4 bg-primary-500 rounded-lg px-12 ml-auto text-white text-2xl mb-4 font-semibold focus:outline-none'
                :href="route('auth.login')"
                type='submit'>Login
        </a>
    </GuestLayout>
</template>
