<template>
    <div>
        <Head title="Recordings" />
        
        <Container class="py-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">Recordings</h1>
                <p class="text-primary-400">Watch past streams and events</p>
            </div>

            <!-- Search Bar -->
            <div class="mb-8">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search recordings..."
                    class="w-full px-4 py-3 bg-primary-800 border border-primary-700 rounded-lg text-white placeholder-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    @input="debouncedSearch"
                />
            </div>

            <!-- No Recordings Message -->
            <div v-if="!hasRecordings && !hasPendingRecordings">
                <div class="bg-primary-800 rounded-lg p-8 text-center">
                    <FaVideoSlashIcon class="w-16 h-16 text-primary-600 mx-auto mb-4" />
                    <h2 class="text-xl font-semibold text-primary-300 mb-2">{{ searchQuery ? 'No Recordings Found' : 'No Recordings Available' }}</h2>
                    <p class="text-primary-400">{{ searchQuery ? 'Try adjusting your search' : 'Check back later for recorded streams' }}</p>
                </div>
            </div>

            <!-- Recordings Sections -->
            <div v-else class="space-y-12">
                <!-- Pending Recordings Section -->
                <div v-if="pendingRecordingGroups.length > 0">
                    <div v-for="group in pendingRecordingGroups" :key="group.label" class="mb-12">
                        <h2 class="text-xl font-semibold text-white mb-6">{{ group.label }}</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 2xl:grid-cols-5 gap-4">
                            <PendingRecordingTile
                                v-for="show in group.items"
                                :key="show.id"
                                :show="show"
                            />
                        </div>
                    </div>
                </div>

                <!-- Available Recordings Sections -->
                <div v-for="section in recordingSections" :key="section.label">
                    <h2 class="text-xl font-semibold text-white mb-6">{{ section.label }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 2xl:grid-cols-5 gap-4">
                        <RecordingTile
                            v-for="recording in section.recordings"
                            :key="recording.id"
                            :recording="recording"
                        />
                    </div>
                </div>
            </div>
        </Container>
    </div>
</template>

<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Container from '@/Components/Container.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import PendingRecordingTile from '@/Components/Recordings/PendingRecordingTile.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';

// Define layout
defineOptions({
    layout: AuthenticatedLayout
});

const props = defineProps({
    recordings: {
        type: Array,
        required: true
    },
    pendingShows: {
        type: Array,
        default: () => []
    },
    search: {
        type: String,
        default: ''
    }
});

// Search functionality
const searchQuery = ref(props.search);
const form = useForm({});

const debouncedSearch = useDebounceFn(() => {
    form.get(route('recordings.index', searchQuery.value ? { search: searchQuery.value } : {}), {
        preserveState: true,
        preserveScroll: true,
        only: ['recordings', 'pendingShows']
    });
}, 500);

// Group recordings by date (year + weekday)
const recordingSections = computed(() => {
    const sections = [];
    const groupedByDay = {};

    props.recordings.forEach(recording => {
        const recordingDate = new Date(recording.date);
        const year = recordingDate.getFullYear();
        const weekday = recordingDate.toLocaleDateString('en-US', { weekday: 'long' });
        const dayKey = `${year} - ${weekday}`;
        
        if (!groupedByDay[dayKey]) {
            groupedByDay[dayKey] = {
                date: recordingDate,
                recordings: []
            };
        }
        groupedByDay[dayKey].recordings.push(recording);
    });

    // Sort by date (most recent first) and create sections
    Object.keys(groupedByDay)
        .sort((a, b) => groupedByDay[b].date - groupedByDay[a].date)
        .forEach(dayKey => {
            sections.push({
                label: dayKey,
                recordings: groupedByDay[dayKey].recordings
            });
        });

    return sections;
});

// Group pending recordings by date (year + weekday)
const pendingRecordingGroups = computed(() => {
    if (!props.pendingShows || props.pendingShows.length === 0) return [];

    const groups = [];
    const groupedByDay = {};

    props.pendingShows.forEach(show => {
        const showDate = new Date(show.scheduled_end || show.actual_end);
        const year = showDate.getFullYear();
        const weekday = showDate.toLocaleDateString('en-US', { weekday: 'long' });
        const dayKey = `${year} - ${weekday} (Processing)`;
        
        if (!groupedByDay[dayKey]) {
            groupedByDay[dayKey] = {
                date: showDate,
                items: []
            };
        }
        groupedByDay[dayKey].items.push(show);
    });

    // Sort by date (most recent first) and create groups
    Object.keys(groupedByDay)
        .sort((a, b) => groupedByDay[b].date - groupedByDay[a].date)
        .forEach(dayKey => {
            groups.push({
                label: dayKey,
                items: groupedByDay[dayKey].items
            });
        });

    return groups;
});

const hasRecordings = computed(() => props.recordings.length > 0);
const hasPendingRecordings = computed(() => props.pendingShows && props.pendingShows.length > 0);

</script>