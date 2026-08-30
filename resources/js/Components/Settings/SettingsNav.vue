<script setup>
/**
 * The settings menu.
 *
 * A rail on the desktop and a scrollable row of pills on a phone: the list is short
 * enough to show whole, and a dropdown would hide where somebody is. Each entry is a
 * page of its own, so a section is linkable and the browser's back button works.
 */
import { Link } from '@inertiajs/vue3'
import { BellRing, KeyRound, SlidersHorizontal, User } from 'lucide-vue-next'

defineProps({
    navigation: { type: Array, default: () => [] },
    current: { type: String, default: null },
})

const icons = { bell: BellRing, key: KeyRound, sliders: SlidersHorizontal, user: User }
</script>

<template>
    <nav aria-label="Settings">
        <!-- Phone: one scrollable row, so a fourth and fifth section do not push the
             page content off the screen. -->
        <ul class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:hidden scrollbar-none">
            <li v-for="entry in navigation" :key="entry.key" class="shrink-0">
                <Link :href="entry.url" class="settings-pill" :class="{ 'settings-pill-on': entry.key === current }">
                    <component :is="icons[entry.icon]" v-if="icons[entry.icon]" class="size-4" :stroke-width="1.8" />
                    {{ entry.label }}
                </Link>
            </li>
        </ul>

        <ul class="hidden md:flex md:flex-col md:gap-0.5">
            <li v-for="entry in navigation" :key="entry.key">
                <Link :href="entry.url" class="settings-link" :class="{ 'settings-link-on': entry.key === current }">
                    <component :is="icons[entry.icon]" v-if="icons[entry.icon]" class="size-4 shrink-0" :stroke-width="1.8" />
                    {{ entry.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
@reference "../../../css/app.css";

.settings-pill {
    @apply inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-primary-800 px-3.5 py-2 text-sm text-primary-300 transition-colors hover:text-primary-100;
}

.settings-pill-on {
    @apply border-primary-500 bg-primary-500/15 text-primary-50;
}

.settings-link {
    @apply flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-primary-300 transition-colors hover:bg-primary-900/60 hover:text-primary-100;
}

.settings-link-on {
    @apply bg-primary-500/15 text-primary-50;
}
</style>
