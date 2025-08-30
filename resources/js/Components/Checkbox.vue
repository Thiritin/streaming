<script setup>
import { Checkbox } from '@/Components/ui/checkbox';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
});
</script>

<template>
    <Checkbox
        :checked="checked"
        @update:checked="emit('update:checked', $event)"
        :value="value"
    />
</template>
