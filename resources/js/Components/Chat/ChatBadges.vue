<script setup>
defineProps({
    badges: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <span v-if="badges.length" class="inline-flex items-center gap-1 align-middle">
        <span
            v-for="badge in badges"
            :key="badge.slug"
            :title="badge.name"
            class="inline-flex h-4 min-w-[1.6rem] items-center justify-center rounded px-1 text-[9px] font-bold uppercase leading-none tracking-wider"
            :style="{
                backgroundColor: badge.color + '2e',
                border: `1px solid ${badge.color}`,
                color: badge.color,
            }"
        >
            {{ badge.label }}
        </span>
    </span>
</template>
