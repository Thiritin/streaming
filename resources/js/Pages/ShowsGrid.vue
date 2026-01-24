<template>
  <div class="min-h-screen">
    <Head title="Live Streams" />

    <!-- Hero Section - Featured Live Stream -->
    <div v-if="liveShows.length > 0" class="relative">
      <div class="absolute inset-0 bg-gradient-to-b from-primary-900/50 via-primary-900/80 to-primary-900 z-10" />
      <div
        class="absolute inset-0 bg-cover bg-center blur-sm opacity-30"
        :style="{ backgroundImage: liveShows[0]?.thumbnail_url ? `url(${liveShows[0].thumbnail_url})` : 'none' }"
      />

      <div class="relative z-20 px-4 sm:px-6 lg:px-8 pt-6 pb-8">
        <div class="max-w-5xl mx-auto">
          <!-- Featured Stream Preview -->
          <Link
            :href="route('show.view', liveShows[0].slug)"
            class="block group"
          >
            <div class="relative aspect-video rounded-xl overflow-hidden bg-primary-800 shadow-2xl ring-1 ring-white/10">
              <img
                v-if="liveShows[0]?.thumbnail_url"
                :src="liveShows[0].thumbnail_url"
                :alt="liveShows[0].title"
                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
              />
              <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-700 to-primary-900">
                <FaVideoIcon class="w-24 h-24 text-primary-500" />
              </div>

              <!-- Live Badge -->
              <div class="absolute top-4 left-4 flex items-center gap-2">
                <span class="live-badge">
                  LIVE
                </span>
                <span v-if="liveShows[0]?.viewer_count" class="viewer-badge">
                  <FaUsersIcon class="w-3.5 h-3.5" />
                  {{ formatViewerCount(liveShows[0].viewer_count) }}
                </span>
              </div>

              <!-- Play overlay -->
              <div class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/40 transition-all duration-300">
                <div class="w-20 h-20 rounded-full bg-primary-500/90 flex items-center justify-center opacity-0 group-hover:opacity-100 transform scale-75 group-hover:scale-100 transition-all duration-300">
                  <FaPlayIcon class="w-8 h-8 text-white ml-1" />
                </div>
              </div>

              <!-- Duration -->
              <div v-if="liveShows[0]?.started_at" class="absolute bottom-4 right-4">
                <span class="bg-black/80 text-white px-2 py-1 rounded text-sm font-medium">
                  {{ formatDuration(liveShows[0].started_at) }}
                </span>
              </div>
            </div>
          </Link>

          <!-- Featured Stream Info - Below video -->
          <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="text-xl lg:text-2xl font-bold text-white leading-tight">
                {{ liveShows[0]?.title }}
              </h1>
              <p v-if="liveShows[0]?.source" class="text-primary-400 mt-1">
                {{ liveShows[0].source }}
              </p>
            </div>
            <Link
              :href="route('show.view', liveShows[0].slug)"
              class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-primary-500 hover:bg-primary-400 text-white font-semibold rounded-lg transition-all duration-200 hover:shadow-lg hover:shadow-primary-500/25 shrink-0"
            >
              <FaPlayIcon class="w-4 h-4" />
              Watch Now
            </Link>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="px-4 sm:px-6 lg:px-8 py-8 space-y-10">

        <!-- Live Now Section (other live streams) -->
        <section v-if="liveShows.length > 1">
          <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
              <div class="w-1 h-6 bg-red-500 rounded-full" />
              <h2 class="text-xl font-bold text-white">Live</h2>
            </div>
          </div>

          <div class="stream-grid">
            <ShowTile
              v-for="show in liveShows.slice(1)"
              :key="show.id"
              :show="show"
            />
          </div>
        </section>

        <!-- Popular Recordings - Always show when available -->
        <section v-if="popularRecordings.length > 0">
          <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
              <div class="w-1 h-6 bg-primary-500 rounded-full" />
              <h2 class="text-xl font-bold text-white">Popular Recordings</h2>
              <span class="hidden sm:inline-flex px-2.5 py-1 bg-primary-500/20 text-primary-400 text-xs font-semibold rounded-full">
                Most viewed
              </span>
            </div>
            <Link
              :href="route('recordings.index')"
              class="text-sm text-primary-400 hover:text-primary-300 transition-colors"
            >
              View all →
            </Link>
          </div>

          <div class="stream-grid">
            <RecordingTile
              v-for="recording in popularRecordings"
              :key="recording.id"
              :recording="recording"
            />
          </div>
        </section>

        <!-- Starting Soon Section -->
        <section v-if="startingSoonShows.length > 0">
          <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
              <div class="w-1 h-6 bg-orange-500 rounded-full" />
              <h2 class="text-xl font-bold text-white">Starting Soon</h2>
              <span class="px-2.5 py-1 bg-orange-500/20 text-orange-400 text-xs font-semibold rounded-full">
                {{ startingSoonShows.length }} upcoming
              </span>
            </div>
          </div>

          <div class="stream-grid">
            <ShowTile
              v-for="show in startingSoonShows"
              :key="show.id"
              :show="show"
            />
          </div>
        </section>

        <!-- Upcoming Shows Section -->
        <section v-if="upcomingShows.length > 0">
          <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
              <div class="w-1 h-6 bg-primary-500 rounded-full" />
              <h2 class="text-xl font-bold text-white">Upcoming Shows</h2>
              <span class="px-2.5 py-1 bg-primary-500/20 text-primary-400 text-xs font-semibold rounded-full">
                Next 24 hours
              </span>
            </div>
          </div>

          <div class="stream-grid">
            <ShowTile
              v-for="show in upcomingShows"
              :key="show.id"
              :show="show"
            />
          </div>
        </section>

        <!-- Empty State -->
        <section v-if="liveShows.length === 0 && startingSoonShows.length === 0 && upcomingShows.length === 0 && popularRecordings.length === 0" class="py-16">
          <div class="text-center max-w-md mx-auto">
            <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-primary-800/50 flex items-center justify-center">
              <FaClockIcon class="w-10 h-10 text-primary-500" />
            </div>
            <h2 class="text-2xl font-bold text-white mb-3">No Shows Scheduled</h2>
            <p class="text-primary-400">Check back later for upcoming streams and events.</p>
          </div>
        </section>

    </div>
  </div>
</template>

<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowTile from '@/Components/Shows/ShowTile.vue';
import RecordingTile from '@/Components/Recordings/RecordingTile.vue';
import FaVideoSlashIcon from '@/Components/Icons/FaVideoSlashIcon.vue';
import FaVideoIcon from '@/Components/Icons/FaVideoIcon.vue';
import FaPlayIcon from '@/Components/Icons/FaPlayIcon.vue';
import FaClockIcon from '@/Components/Icons/FaClockIcon.vue';
import FaUsersIcon from '@/Components/Icons/FaUsersIcon.vue';

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
  startingSoonShows: {
    type: Array,
    default: () => [],
  },
  upcomingShows: {
    type: Array,
    default: () => [],
  },
  popularRecordings: {
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
const startingSoonShows = ref(props.startingSoonShows);
const upcomingShows = ref(props.upcomingShows);
const popularRecordings = ref(props.popularRecordings);

let refreshInterval;

// Format viewer count (1000 -> 1K, etc.)
const formatViewerCount = (count) => {
  if (count >= 1000000) {
    return (count / 1000000).toFixed(1) + 'M';
  }
  if (count >= 1000) {
    return (count / 1000).toFixed(1) + 'K';
  }
  return count?.toString() || '0';
};

// Format duration
const formatDuration = (startTime) => {
  const start = new Date(startTime);
  const now = new Date();
  const diff = Math.floor((now - start) / 1000);

  const hours = Math.floor(diff / 3600);
  const minutes = Math.floor((diff % 3600) / 60);
  const seconds = diff % 60;

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
};

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

        // Remove from starting soon if exists
        const startingSoonIndex = startingSoonShows.value.findIndex(s => s.id === e.show.id);
        if (startingSoonIndex !== -1) {
          startingSoonShows.value.splice(startingSoonIndex, 1);
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

        // Remove from starting soon if exists
        const startingSoonIndex = startingSoonShows.value.findIndex(s => s.id === e.show.id);
        if (startingSoonIndex !== -1) {
          startingSoonShows.value.splice(startingSoonIndex, 1);
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
@reference "../../css/app.css";

.stream-grid {
  display: grid;
  grid-template-columns: repeat(1, minmax(0, 1fr));
  gap: 1.5rem;
}

@media (min-width: 640px) {
  .stream-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 768px) {
  .stream-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (min-width: 1280px) {
  .stream-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (min-width: 1536px) {
  .stream-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }
}

.live-badge {
  @apply inline-flex items-center gap-1.5 bg-red-600 text-white px-2.5 py-1 rounded text-xs font-bold uppercase tracking-wide;
}

.live-dot {
  @apply w-2 h-2 bg-white rounded-full;
  animation: blink 1.5s ease-in-out infinite;
}

.viewer-badge {
  @apply inline-flex items-center gap-1.5 bg-black/70 backdrop-blur-sm text-white px-2.5 py-1 rounded text-xs font-medium;
}

.live-counter {
  @apply px-2.5 py-1 bg-red-500/20 text-red-400 text-xs font-semibold rounded-full;
}

.stream-grid > * {
  animation: fadeIn 0.4s ease-out forwards;
  opacity: 0;
}

.stream-grid > *:nth-child(1) { animation-delay: 0.05s; }
.stream-grid > *:nth-child(2) { animation-delay: 0.1s; }
.stream-grid > *:nth-child(3) { animation-delay: 0.15s; }
.stream-grid > *:nth-child(4) { animation-delay: 0.2s; }
.stream-grid > *:nth-child(5) { animation-delay: 0.25s; }
.stream-grid > *:nth-child(6) { animation-delay: 0.3s; }
.stream-grid > *:nth-child(7) { animation-delay: 0.35s; }
.stream-grid > *:nth-child(8) { animation-delay: 0.4s; }
</style>
