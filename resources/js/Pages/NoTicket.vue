<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from "@/Components/Logo.vue";
import { Head, usePage } from '@inertiajs/vue3'
import LoginScreenWelcome from "@/Components/LoginScreenWelcome.vue";

const page = usePage();

// Named where there is a convention name to name, otherwise the generic wording
// still reads correctly.
const conventionName = computed(() => page.props.branding?.conventionName ?? '');
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Unauthorized</title>
        </Head>
        <Logo class="h-11 w-auto"></Logo>
        <LoginScreenWelcome title="Only Attendees" sub-title="Unauthorized Access" class="mt-12 mb-4"></LoginScreenWelcome>
        <p class="text-primary-300 text-sm mb-3">
            The current stream is limited to staff and members<template v-if="conventionName"> of {{ conventionName }}</template>.
        </p>
    </GuestLayout>
</template>
