<script setup>
import { Button } from '@/Components/ui/button'

defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <Button variant="secondary" :type="type">
        <slot />
    </Button>
</template>
