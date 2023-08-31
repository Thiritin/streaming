<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from "@/Components/Logo.vue";
import { Head } from '@inertiajs/vue3'
import LoginScreenWelcome from "@/Components/LoginScreenWelcome.vue";
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Login</title>
        </Head>
        <Logo></Logo>
        <LoginScreenWelcome title="Livestream" sub-title="Registration Required" class="mb-4"></LoginScreenWelcome>
        <p class="text-primary-300 text-sm mb-3 u">A verified Eurofurence Identity account is required, to access this page. <a class="underline" href="https://identity.eurofurence.org/auth/register" target="_blank">Don't have one? Create one here for free!</a></p>
        <a
                class='py-3 mt-4 bg-primary-500 rounded-lg px-12 ml-auto text-white text-2xl mb-4 font-semibold focus:outline-none'
                :href="route('auth.login')"
                type='submit'>Login
        </a>
    </GuestLayout>
</template>
