<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    modelValue: { type: String, default: '' },
    autocomplete: { type: String, default: null },
    error: { type: String, default: null },
    autofocus: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const input = ref(null);

// A page reached through an Inertia visit is mounted, not loaded, so the
// autofocus attribute alone never fires.
onMounted(() => props.autofocus && input.value?.focus());
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label :for="id" class="text-sm font-medium text-primary-300">{{ label }}</label>

        <input
            :id="id"
            ref="input"
            :type="type"
            :value="modelValue"
            :autocomplete="autocomplete"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : null"
            :aria-describedby="error ? `${id}-error` : null"
            class="w-full rounded-lg border border-white/10 bg-primary-950/40 px-4 py-2.5 text-white outline-none transition-colors duration-(--dur-base) focus:border-primary-400 focus-visible:ring-2 focus-visible:ring-primary-300/50 disabled:opacity-60"
            @input="$emit('update:modelValue', $event.target.value)"
        />

        <p v-if="error" :id="`${id}-error`" class="text-sm text-red-400">{{ error }}</p>
    </div>
</template>
