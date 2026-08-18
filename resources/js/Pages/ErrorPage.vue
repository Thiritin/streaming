<template>
  <div class="min-h-screen">
    <Head :title="title" />

    <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 py-24">
      <div class="max-w-xl space-y-4">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-300 tabular-nums">Error {{ status }}</p>
        <h1 class="text-3xl sm:text-4xl font-bold text-white tracking-tight">{{ title }}</h1>
        <p class="text-primary-300">{{ description }}</p>

        <div class="flex flex-wrap gap-3 pt-2">
          <Link
            :href="route('shows.grid')"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 hover:bg-primary-400 text-white text-sm font-semibold transition-colors"
          >
            Back to browse
          </Link>
          <Link
            :href="route('schedule.index')"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-primary-700 hover:border-primary-500 text-primary-200 hover:text-white text-sm font-semibold transition-colors"
          >
            Full schedule
          </Link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  status: { type: Number, required: true },
});

const TITLES = {
  403: 'No access',
  404: 'Page not found',
  419: 'Page expired',
  429: 'Too many requests',
  500: 'Something broke',
  503: 'Down for maintenance',
};

const DESCRIPTIONS = {
  403: 'Your account cannot open this page.',
  404: 'That link does not go anywhere. It may have moved.',
  419: 'You were away a while. Load the page again and retry.',
  429: 'Slow down a moment, then try again.',
  500: 'Not your fault. Try again in a moment.',
  503: 'We are working on the site. Back shortly.',
};

const title = computed(() => TITLES[props.status] ?? 'Something broke');
const description = computed(() => DESCRIPTIONS[props.status] ?? 'Not your fault. Try again in a moment.');
</script>
