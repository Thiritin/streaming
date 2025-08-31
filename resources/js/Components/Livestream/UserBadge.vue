<script setup>
import { computed } from 'vue';

const props = defineProps({
    role: {
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
    if (!props.role || !props.role.metadata?.badge) return {};

    // Use role's chat color for badge background, or fallback to default colors
    const colorMap = {
        'admin': '#dc2626', // Red
        'moderator': '#16a34a', // Green
        'sponsor': '#fbbf24', // Yellow/Gold
        'supersponsor': '#a855f7', // Purple
        'staff': '#3b82f6', // Blue
    };

    const backgroundColor = props.role.chat_color || colorMap[props.role.slug] || '#6b7280';
    
    // Determine text color based on background brightness
    const isLight = backgroundColor === '#fbbf24' || backgroundColor === '#f6cb21';
    
    return {
        backgroundColor: backgroundColor,
        color: isLight ? '#000000' : '#ffffff'
    };
});
</script>

<template>
    <span v-if="role && role.metadata?.badge" :class="badgeClasses" :style="badgeStyles">
        {{ role.metadata.badge }}
    </span>
</template>