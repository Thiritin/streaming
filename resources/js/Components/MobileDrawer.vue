<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  },
  position: {
    type: String,
    default: 'right',
    validator: (value) => ['left', 'right'].includes(value)
  },
  width: {
    type: String,
    default: 'w-80'
  }
});

const emit = defineEmits(['close']);

// Handle escape key
const handleEscape = (e) => {
  if (e.key === 'Escape' && props.isOpen) {
    emit('close');
  }
};

// Handle body scroll lock
watch(() => props.isOpen, (newValue) => {
  if (newValue) {
    document.body.style.overflow = 'hidden';
  } else {
    document.body.style.overflow = '';
  }
});

onMounted(() => {
  document.addEventListener('keydown', handleEscape);
});

onUnmounted(() => {
  document.removeEventListener('keydown', handleEscape);
  document.body.style.overflow = '';
});
</script>

<template>
  <!-- Backdrop -->
  <Transition
    enter-active-class="transition-opacity ease-out duration-300"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity ease-in duration-200"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="isOpen"
      class="fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden"
      @click="emit('close')"
    ></div>
  </Transition>

  <!-- Drawer -->
  <Transition
    :enter-active-class="`transition-transform ease-out duration-300`"
    :enter-from-class="position === 'right' ? 'translate-x-full' : '-translate-x-full'"
    enter-to-class="translate-x-0"
    :leave-active-class="`transition-transform ease-in duration-200`"
    leave-from-class="translate-x-0"
    :leave-to-class="position === 'right' ? 'translate-x-full' : '-translate-x-full'"
  >
    <div
      v-if="isOpen"
      :class="[
        'fixed top-0 h-full bg-primary-900 shadow-xl z-50 md:hidden',
        width,
        position === 'right' ? 'right-0' : 'left-0'
      ]"
    >
      <!-- Drawer Header -->
      <div class="flex items-center justify-between p-4 border-b border-primary-800">
        <slot name="header">
          <h2 class="text-lg font-semibold text-white">Menu</h2>
        </slot>
        <button
          @click="emit('close')"
          type="button"
          class="p-2 rounded-lg hover:bg-primary-800 transition-colors"
        >
          <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- Drawer Content -->
      <div class="flex-1 overflow-y-auto h-[calc(100%-4rem)]">
        <slot></slot>
      </div>
    </div>
  </Transition>
</template>