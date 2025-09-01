<template>
  <div>
    <Head title="Live Streams" />

    <Container class="py-8">
      <!-- Header -->
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-white mb-2">Live Streams</h1>
        <p class="text-primary-400">Watch live shows and catch up on recorded content</p>
      </div>

      <!-- Live Shows Section -->
      <div v-if="liveShows.length > 0" class="mb-12">
        <div class="flex items-center mb-4">
          <h2 class="text-2xl font-semibold text-white">Live Now</h2>
          <span class="ml-3 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold uppercase">
            {{ liveShows.length }} LIVE
          </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
          <ShowTile
            v-for="show in liveShows"
            :key="show.id"
            :show="show"
          />
        </div>
      </div>

      <!-- No Live Shows Message -->
      <div v-else class="mb-12">
        <div class="bg-primary-800 rounded-lg p-8 text-center">
          <FaVideoSlashIcon class="w-16 h-16 text-primary-600 mx-auto mb-4" />
          <h2 class="text-xl font-semibold text-primary-300 mb-2">No Live Streams</h2>
          <p class="text-primary-400">Check back later or browse upcoming shows below</p>
        </div>
      </div>

      <!-- Upcoming Shows Section -->
      <div v-if="upcomingShows.length > 0">
        <div class="flex items-center mb-4">
          <h2 class="text-2xl font-semibold text-white">Upcoming Shows</h2>
          <span class="ml-3 bg-primary-700 text-primary-200 px-2 py-1 rounded text-xs">
            Next 24 hours
          </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
          <ShowTile
            v-for="show in upcomingShows"
            :key="show.id"
            :show="show"
          />
        </div>
      </div>

      <!-- No Upcoming Shows -->
      <div v-else-if="liveShows.length === 0" class="mt-8">
        <div class="bg-primary-800 rounded-lg p-8 text-center">
          <h2 class="text-xl font-semibold text-primary-300 mb-2">No Upcoming Shows</h2>
          <p class="text-primary-400">No shows scheduled for the next 24 hours</p>
        </div>
      </div>

      <!-- Admin Link -->
      <div v-if="page.props.auth.user.is_staff" class="mt-12 border-t border-primary-700 pt-8">
        <div class="flex justify-center">
          <a
            href="/admin"
            class="inline-flex items-center px-4 py-2 bg-primary-700 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >
            <FaCogIcon class="w-4 h-4 mr-2" />
            Admin Panel
          </a>
        </div>
      </div>
    </Container>
  </div>
</template>

<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowTile from '@/Components/Shows/ShowTile.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';
import FaCogIcon from '@/Components/Icons/FaCogIcon.vue';
import Container from '@/Components/Container.vue';

// Define layout
defineOptions({
  layout: AuthenticatedLayout
});

// Props
const props = defineProps({
  liveShows: {
    type: Array,
    default: () => [],
  },
  upcomingShows: {
    type: Array,
    default: () => [],
  },
  currentTime: {
    type: String,
    required: false,
  },
});

// Page props for auth info
const page = usePage();

// Reactive state
const liveShows = ref(props.liveShows);
const upcomingShows = ref(props.upcomingShows);

let refreshInterval;

onMounted(() => {
  // Listen for show status updates
  Echo.channel('shows')
    .listen('.show.status.changed', (e) => {
      // Handle show going live
      if (e.status === 'live') {
        // Remove from upcoming if exists
        const upcomingIndex = upcomingShows.value.findIndex(s => s.id === e.show.id);
        if (upcomingIndex !== -1) {
          upcomingShows.value.splice(upcomingIndex, 1);
        }

        // Add to live shows if not already there
        const liveIndex = liveShows.value.findIndex(s => s.id === e.show.id);
        if (liveIndex === -1) {
          liveShows.value.unshift(e.show);
        }
      }

      // Handle show ending
      if (e.status === 'ended') {
        // Remove from live shows
        const liveIndex = liveShows.value.findIndex(s => s.id === e.show.id);
        if (liveIndex !== -1) {
          liveShows.value.splice(liveIndex, 1);
        }
      }
    })
    .listen('.show.viewer.count', (e) => {
      // Update viewer count for live shows
      const show = liveShows.value.find(s => s.id === e.show_id);
      if (show) {
        show.viewer_count = e.viewer_count;
      }
    })
    .listen('.thumbnail.updated', (e) => {
      // Update thumbnail for any show
      const liveShow = liveShows.value.find(s => s.id === e.show_id);
      if (liveShow) {
        liveShow.thumbnail_url = e.thumbnail_url;
      }

      const upcomingShow = upcomingShows.value.find(s => s.id === e.show_id);
      if (upcomingShow) {
        upcomingShow.thumbnail_url = e.thumbnail_url;
      }
    });

  // Auto-refresh page every 5 minutes to get fresh data
  refreshInterval = setInterval(() => {
    window.location.reload();
  }, 5 * 60 * 1000);
});

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
  }
  Echo.leave('shows');
});
</script>

<style>
.grid > * {
  animation: fadeIn 0.5s ease-out forwards;
}

.grid > *:nth-child(1) { animation-delay: 0.05s; }
.grid > *:nth-child(2) { animation-delay: 0.1s; }
.grid > *:nth-child(3) { animation-delay: 0.15s; }
.grid > *:nth-child(4) { animation-delay: 0.2s; }
.grid > *:nth-child(5) { animation-delay: 0.25s; }
.grid > *:nth-child(6) { animation-delay: 0.3s; }
.grid > *:nth-child(7) { animation-delay: 0.35s; }
.grid > *:nth-child(8) { animation-delay: 0.4s; }
</style>
