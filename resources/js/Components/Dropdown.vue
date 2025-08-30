<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    align: {
        type: String,
        default: 'right',
    },
    width: {
        type: String,
        default: '48',
    },
    contentClasses: {
        type: String,
        default: '',
    },
});

const open = ref(false);
const dropdownRef = ref(null);

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

const closeOnClickOutside = (e) => {
    if (dropdownRef.value && !dropdownRef.value.contains(e.target)) {
        open.value = false;
    }
};

onMounted(() => {
    document.addEventListener('keydown', closeOnEscape);
    document.addEventListener('click', closeOnClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.removeEventListener('click', closeOnClickOutside);
});

const widthClass = {
    48: 'w-48',
    56: 'w-56',
    64: 'w-64',
}[props.width.toString()] || 'w-48';

const alignmentClasses = {
    left: 'left-0',
    right: 'right-0',
}[props.align] || 'right-0';
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <div @click="open = !open">
            <slot name="trigger" />
        </div>

        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 mt-2 rounded-md shadow-lg"
                :class="[widthClass, alignmentClasses]"
                @click="open = false"
            >
                <div
                    class="rounded-md bg-primary-700 ring-1 ring-black ring-opacity-5 py-1"
                    :class="contentClasses"
                >
                    <slot name="content" />
                </div>
            </div>
        </transition>
    </div>
</template>