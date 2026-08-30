<script setup>
/**
 * One row of a settings list. Padding matches the card's heading, so the rules run the
 * full width while the content stays aligned with it.
 */
defineProps({
    // A row whose content is a sentence rather than a label with a control beside it.
    block: { type: Boolean, default: false },
})
</script>

<template>
    <li class="px-5 py-3.5 sm:px-6">
        <div v-if="block"><slot /></div>
        <div v-else class="flex items-center justify-between gap-4"><slot /></div>
    </li>
</template>
