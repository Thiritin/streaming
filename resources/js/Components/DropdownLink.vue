<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <a
        :href="href"
        class="block w-full px-4 py-2 text-left text-sm leading-5 text-primary-700 dark:text-primary-300 hover:bg-primary-100 dark:hover:bg-primary-800 focus:outline-none focus:bg-primary-100 dark:focus:bg-primary-800 transition duration-150 ease-in-out"
    >
        <slot />
    </a>
</template>
