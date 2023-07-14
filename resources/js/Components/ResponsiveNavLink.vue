<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    component: {
        type: String,
        default: Link,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full pl-3 pr-4 py-2 border-l-4 border-primary-400 dark:border-primary-600 text-left text-base font-medium text-primary-700 dark:text-primary-300 bg-primary-50 dark:bg-primary-900/50 focus:outline-none focus:text-primary-800 dark:focus:text-primary-200 focus:bg-primary-100 dark:focus:bg-primary-900 focus:border-primary-700 dark:focus:border-primary-300 transition duration-150 ease-in-out'
        : 'block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-200 hover:bg-primary-50 dark:hover:bg-primary-700 hover:border-primary-300 dark:hover:border-primary-600 focus:outline-none focus:text-primary-800 dark:focus:text-primary-200 focus:bg-primary-50 dark:focus:bg-primary-700 focus:border-primary-300 dark:focus:border-primary-600 transition duration-150 ease-in-out'
);
</script>

<template>
    <component :is="component" :href="href" :class="classes">
        <slot />
    </component>
</template>
