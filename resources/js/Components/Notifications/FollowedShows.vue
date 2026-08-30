<script setup>
/**
 * The shows this viewer is waiting on.
 *
 * A row leaves the list once its recording notification has actually gone out: the
 * follow was a question, and it has been answered.
 */
import { Link, router } from '@inertiajs/vue3'
import SettingsList from '@/Components/Settings/SettingsList.vue'
import SettingsRow from '@/Components/Settings/SettingsRow.vue'

defineProps({
    shows: { type: Array, default: () => [] },
})

function unfollow(show) {
    router.delete(route('notifications.shows.unfollow', show.id), {
        preserveScroll: true,
        preserveState: true,
    })
}

function showTime(value) {
    if (!value) return null
    return new Date(value).toLocaleString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<template>
    <SettingsList>
        <SettingsRow v-if="!shows.length" block>
            <p class="text-sm text-primary-400">Nothing followed.</p>
        </SettingsRow>

        <SettingsRow v-for="show in shows" :key="show.id">
            <span class="min-w-0">
                <Link :href="route('show.view', show.slug)" class="block truncate text-sm text-primary-100 hover:text-white">
                    {{ show.title }}
                </Link>
                <span class="mt-0.5 block truncate text-xs text-primary-500">
                    {{ showTime(show.scheduled_start) }}
                </span>
            </span>
            <button
                type="button"
                class="shrink-0 rounded-md border border-primary-700 px-3 py-1.5 text-xs font-medium text-primary-200 hover:border-primary-500 hover:text-primary-50"
                @click="unfollow(show)"
            >
                Unfollow
            </button>
        </SettingsRow>
    </SettingsList>
</template>
