<script setup>
import FaVideoIcon from "@/Components/Icons/FaVideoIcon.vue";
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex h-full items-center justify-center bg-black rounded-lg min-h-[80vh]">
        <div class="text-center p-8">
            <FaVideoIcon class="fill-current text-primary-400 text-[6rem] block mx-auto mb-8"></FaVideoIcon>
            
            <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-white mb-4">
                Stream Currently Offline
            </h2>
            
            <p class="text-primary-300 text-lg mb-6 max-w-md mx-auto">
                This page will automatically refresh once the stream is back online.
            </p>
            
            <Link
                :href="route('shows.index')"
                class="inline-block px-6 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors font-semibold"
            >
                Browse Other Shows
            </Link>
        </div>
    </div>
</template>

<style>

</style>
