<script setup>
/**
 * One card on a settings page: a heading, then a list.
 *
 * The heading is padded and the slot is not, so a list inside can run its rules the
 * full width of the card. A rule that stops short of the edge reads as a border round
 * something rather than as a list.
 */
defineProps({
    title: { type: String, required: true },
    icon: { type: Object, default: null },
})
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-primary-800 bg-primary-950/60">
        <div class="flex items-center gap-2.5 px-5 py-4 sm:px-6">
            <component :is="icon" v-if="icon" class="size-4 shrink-0 text-primary-400" :stroke-width="1.8" />
            <h2 class="text-sm font-semibold text-primary-50">{{ title }}</h2>
        </div>

        <slot />
    </section>
</template>
