<!-- Installations set their own logo in the admin panel. The fallback below is a
     neutral broadcast mark drawn in currentColor, so nothing here is tied to a
     single convention. -->
<template>
    <img
        v-if="logoUrl"
        :src="logoUrl"
        :alt="conventionName"
        class="block h-full w-auto object-contain"
    />
    <svg
        v-else
        viewBox="0 0 64 64"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        :aria-label="conventionName || 'Logo'"
    >
        <circle cx="32" cy="32" r="30" fill="currentColor" fill-opacity="0.12" />
        <circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="3" />
        <!-- Play glyph -->
        <path d="M26 22.5 44 32 26 41.5z" fill="currentColor" />
        <!-- Broadcast arcs on either side of the glyph -->
        <path
            d="M17.5 21a19 19 0 0 0 0 22M46.5 21a19 19 0 0 1 0 22"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
        />
    </svg>
</template>
<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const logoUrl = computed(() => page.props.branding?.logoUrl ?? null);
const conventionName = computed(() => page.props.branding?.conventionName ?? '');
</script>
