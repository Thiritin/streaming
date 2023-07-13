<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Stream" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-primary-800 dark:text-primary-200 leading-tight">Stream</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-primary-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-primary-900 dark:text-primary-100">There is currently no active stream. Please check back at a later time.</div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
