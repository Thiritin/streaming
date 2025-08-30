<script setup>
import { computed } from 'vue';

const props = defineProps({
    badge: {
        type: Object,
        default: null
    },
    size: {
        type: String,
        default: 'sm', // sm, md, lg
    }
});

const badgeClasses = computed(() => {
    const sizeClasses = {
        'sm': 'text-xs px-1 py-0.5',
        'md': 'text-sm px-1.5 py-1',
        'lg': 'text-base px-2 py-1.5'
    };

    return [
        'inline-flex items-center justify-center font-bold rounded',
        sizeClasses[props.size]
    ];
});

const badgeStyles = computed(() => {
    if (!props.badge) return {};

    // Define badge styling based on type
    switch (props.badge.type) {
        case 'admin':
            return {
                backgroundColor: '#dc2626', // Red
                color: '#ffffff'
            };
        case 'moderator':
            return {
                backgroundColor: '#16a34a', // Green
                color: '#ffffff'
            };
        case 'subscriber_yellow':
            return {
                backgroundColor: '#fbbf24', // Yellow
                color: '#000000'
            };
        case 'subscriber_purple':
            return {
                backgroundColor: '#a855f7', // Purple
                color: '#ffffff'
            };
        default:
            return {};
    }
});
</script>

<template>
    <span v-if="badge" :class="badgeClasses" :style="badgeStyles">
        {{ badge.label }}
    </span>
</template>