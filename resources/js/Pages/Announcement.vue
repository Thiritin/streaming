<script setup>
/**
 * The announcement in full: the banner's line as a standfirst, then everything that
 * would not fit on it. Both halves are rendered server-side by App\Support\Markdown.
 */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MarkdownText from '@/Components/MarkdownText.vue';

defineOptions({
  layout: AuthenticatedLayout,
});

const props = defineProps({
  announcementPage: { type: Object, required: true },
});

const announcement = computed(() => props.announcementPage);

const eyebrow = computed(() => ({
  info: 'Announcement',
  warning: 'Service notice',
  critical: 'Urgent notice',
}[announcement.value.level] ?? 'Announcement'));

const accent = computed(() => ({
  info: 'text-primary-300',
  warning: 'text-amber-300',
  critical: 'text-red-300',
}[announcement.value.level] ?? 'text-primary-300'));
</script>

<template>
  <div class="min-h-screen">
    <Head :title="announcement.title || 'Announcement'" />

    <article class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 pt-10 pb-16">
      <p class="text-xs font-semibold uppercase tracking-[0.14em]" :class="accent">
        {{ eyebrow }}
      </p>

      <h1 v-if="announcement.title" class="mt-2 text-3xl font-bold tracking-tight text-white">
        {{ announcement.title }}
      </h1>

      <MarkdownText
        :html="announcement.summaryHtml"
        class="mt-4 border-l-2 border-primary-700 pl-4 text-lg leading-relaxed text-primary-100"
      />

      <MarkdownText :html="announcement.html" class="mt-8 text-primary-200 leading-relaxed" />

      <Link
        :href="route('shows.grid')"
        class="mt-10 inline-flex items-center gap-1.5 text-sm text-primary-300 transition-colors hover:text-white"
      >
        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Back to the streams
      </Link>
    </article>
  </div>
</template>
