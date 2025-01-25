<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from "@/Components/Logo.vue";
import { Head } from '@inertiajs/vue3'
import LoginScreenWelcome from "@/Components/LoginScreenWelcome.vue";
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Unauthorized</title>
        </Head>
        <Logo></Logo>
        <LoginScreenWelcome title="Only Attendees" sub-title="Unauthorized Access" class="mb-4"></LoginScreenWelcome>
        <p class="text-primary-300 text-sm mb-3">The current steam is limited to staff & members of the Eurofurence e.V.</p>
    </GuestLayout>
</template>
