<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: false,
    },
    // 'Link' for an Inertia visit, 'a' for a plain link off the site, 'button'
    // for an entry that posts - signing out of a local session, say.
    as: {
        type: String,
        default: 'Link',
    },
    method: {
        type: String,
        default: 'get',
    },
});

const classes =
    'flex items-center gap-2 w-full px-4 py-2 text-left text-sm leading-5 text-primary-200 hover:bg-primary-600 hover:text-white focus:outline-none focus:bg-primary-600 transition duration-(--dur-fast) ease-in-out';
</script>

<template>
    <a v-if="as === 'a'" :href="href" :class="classes">
        <slot />
    </a>
    <Link
        v-else
        :href="href"
        :as="as === 'button' ? 'button' : 'a'"
        :method="method"
        :class="classes"
    >
        <slot />
    </Link>
</template>
