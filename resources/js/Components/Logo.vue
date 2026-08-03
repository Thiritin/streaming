<!-- Installations set their own logo in the admin panel. Without one this renders
     nothing at all rather than a stand-in mark, so an installation that has not
     picked a logo shows its name instead of someone else's shape. Callers that
     would be left with an empty box fall back to the site name themselves. -->
<template>
    <!-- No height of its own on purpose. Every caller sizes the mark for where it
         sits (h-5 in the mobile bar, h-8 in the header, h-24 on the login screen),
         and a height here would be a second `height` utility on the same element:
         which one won would come down to stylesheet order rather than the caller.
         max-h-full only bounds it inside a parent that is already sized. -->
    <img
        v-if="logoUrl"
        :src="logoUrl"
        :alt="conventionName"
        class="block w-auto max-h-full object-contain"
    />
</template>
<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const logoUrl = computed(() => page.props.branding?.logoUrl ?? null);
const conventionName = computed(() => page.props.branding?.conventionName ?? '');
</script>
