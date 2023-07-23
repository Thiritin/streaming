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
        ? 'inline-flex items-center px-1 pt-1 border-b-2 border-primary-600 text-sm font-medium leading-5 text-primary-100 focus:outline-none focus:border-primary-700 transition duration-150 ease-in-out'
        : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-primary-400 hover:text-primary-300 hover:border-primary-700 focus:outline-none focus:text-primary-300 focus:border-primary-700 transition duration-150 ease-in-out'
);
</script>

<template>
    <component :is="component" :href="href" :class="classes">
        <slot />
    </component>
</template>
