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
        default: Link,
    },
    prefetch: {
        type: [Boolean, String],
        default: false,
    },
});

// Pill tabs rather than the old underline: an underline on a translucent sticky bar
// reads as a stray border, and the pill gives the current section a shape you can
// actually see at a glance.
const base = 'inline-flex items-center gap-2.5 rounded-full px-4 py-2 text-[15px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400 focus-visible:ring-offset-2 focus-visible:ring-offset-primary-950';

const classes = computed(() =>
    props.active
        ? `${base} bg-primary-700/70 text-white`
        : `${base} text-primary-300 hover:bg-primary-800/70 hover:text-white`
);
</script>

<template>
    <component :is="component" :href="href" :class="classes" :prefetch="prefetch">
        <slot />
    </component>
</template>
