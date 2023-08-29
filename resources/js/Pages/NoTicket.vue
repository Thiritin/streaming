<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from "@/Components/Logo.vue";
import { Head } from '@inertiajs/vue3'
import LoginScreenWelcome from "@/Components/LoginScreenWelcome.vue";
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Purchase Required</title>
        </Head>
        <Logo></Logo>
        <LoginScreenWelcome title="Only Attendees" sub-title="We didn't find a valid ticket" class="mb-4"></LoginScreenWelcome>
        <p class="text-primary-300 text-sm mb-3">If you only want to watch the Eurofurence Stream, you require atleast a day ticket to get access to the stream for the duration of the convention.</p>
        <a
            class='py-3 mt-4 bg-primary-500 rounded-lg px-12 ml-auto text-white text-2xl mb-4 font-semibold focus:outline-none'
            href="https://reg.eurofurence.org/"
            type='submit'>Buy ticket
        </a>
    </GuestLayout>
</template>
