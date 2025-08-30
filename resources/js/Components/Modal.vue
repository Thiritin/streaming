<script setup>
import { computed, watch } from 'vue';
import { Dialog, DialogContent, DialogOverlay } from '@/Components/ui/dialog';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);

const isOpen = computed({
    get: () => props.show,
    set: (value) => {
        if (!value && props.closeable) {
            emit('close');
        }
    },
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent :class="maxWidthClass" :closeable="closeable">
            <slot />
        </DialogContent>
    </Dialog>
</template>
