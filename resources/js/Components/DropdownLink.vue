<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: false,
    },
    as: {
        type: String,
        default: 'Link',
    },
});
</script>

<template>
    <div>
        <Link
            v-if="as === 'Link'"
            :href="href"
            class="block w-full px-4 py-2 text-left text-sm leading-5 text-primary-200 hover:bg-primary-600 hover:text-white focus:outline-none focus:bg-primary-600 transition duration-150 ease-in-out"
        >
            <slot />
        </Link>
        <a
            v-else
            :href="href"
            class="block w-full px-4 py-2 text-left text-sm leading-5 text-primary-200 hover:bg-primary-600 hover:text-white focus:outline-none focus:bg-primary-600 transition duration-150 ease-in-out"
        >
            <slot />
        </a>
    </div>
</template>