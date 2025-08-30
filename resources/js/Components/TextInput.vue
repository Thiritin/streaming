<script setup>
import { onMounted, ref } from 'vue';
import { Input } from '@/Components/ui/input';

const props = defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <Input
        :modelValue="modelValue"
        @update:modelValue="emit('update:modelValue', $event)"
        :type="type"
        :placeholder="placeholder"
        :disabled="disabled"
        ref="input"
    />
</template>
