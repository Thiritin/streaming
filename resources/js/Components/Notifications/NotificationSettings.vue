<script setup>
/**
 * The whole notification panel, for the dialog behind the bell in the archive.
 *
 * The same three parts the settings page lays out as cards, as one run of lists: a
 * dialog is already a card, and nesting them turned it into a box of boxes.
 */
import NotificationChannels from '@/Components/Notifications/NotificationChannels.vue'
import NotificationCategories from '@/Components/Notifications/NotificationCategories.vue'
import FollowedShows from '@/Components/Notifications/FollowedShows.vue'

defineProps({
    notifications: { type: Object, required: true },
})
</script>

<template>
    <div class="-mx-5 space-y-5 sm:-mx-6">
        <section>
            <h3 class="px-5 pb-2 text-xs font-semibold uppercase tracking-wider text-primary-400 sm:px-6">Channels</h3>
            <NotificationChannels :notifications="notifications" />
        </section>

        <section>
            <h3 class="px-5 pb-2 text-xs font-semibold uppercase tracking-wider text-primary-400 sm:px-6">Categories</h3>
            <NotificationCategories :notifications="notifications" />
        </section>

        <section v-if="notifications.followedShows.length">
            <h3 class="px-5 pb-2 text-xs font-semibold uppercase tracking-wider text-primary-400 sm:px-6">Shows you follow</h3>
            <FollowedShows :shows="notifications.followedShows" />
        </section>
    </div>
</template>
