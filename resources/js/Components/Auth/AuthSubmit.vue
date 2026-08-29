<script setup>
defineProps({
    label: { type: String, required: true },
    processing: { type: Boolean, default: false },
});
</script>

<template>
    <button
        type="submit"
        :disabled="processing"
        class="inline-flex w-full items-center justify-center rounded-lg bg-primary-500 px-6 py-3 text-lg font-semibold text-white transition-colors duration-(--dur-base) hover:bg-primary-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-primary-900 disabled:opacity-60"
    >
        {{ label }}
    </button>
</template>
