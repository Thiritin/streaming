<template>
    <div>
        <Head title="Recordings" />
        
        <Container class="py-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Recordings</h1>
                <p class="text-primary-400">Watch past streams and events</p>
            </div>

            <!-- No Recordings Message -->
            <div v-if="recordings.length === 0">
                <div class="bg-primary-800 rounded-lg p-8 text-center">
                    <FaVideoSlashIcon class="w-16 h-16 text-primary-600 mx-auto mb-4" />
                    <h2 class="text-xl font-semibold text-primary-300 mb-2">No Recordings Available</h2>
                    <p class="text-primary-400">Check back later for recorded streams</p>
                </div>
            </div>

            <!-- Recordings Grid -->
            <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
                <RecordingTile
                    v-for="recording in recordings"
                    :key="recording.id"
                    :recording="recording"
                />
            </div>
        </Container>
    </div>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Container from '@/Components/Container.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';

// Define layout
defineOptions({
    layout: AuthenticatedLayout
});

const props = defineProps({
    recordings: {
        type: Array,
        required: true
    }
});

</script>