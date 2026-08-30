<script setup>
/**
 * The rows of a settings card.
 *
 * Slightly lighter than the card behind it and ruled edge to edge, which is what makes
 * a stack of controls read as one list instead of as loose paragraphs.
 */
</script>

<template>
    <ul class="divide-y divide-primary-800/80 border-y border-primary-800/80 bg-primary-900/25">
        <slot />
    </ul>
</template>
