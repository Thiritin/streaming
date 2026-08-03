<script setup>
/**
 * Shell for the /manage panel: status strip across the top, permanent sidebar on the
 * left, page content to the right of it.
 *
 * Dark only. The surface and state tokens are defined at :root in app.css rather than
 * behind a .dark class, so there is nothing here to toggle and no flash on first paint.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ManageSidebar from '@/Components/Manage/ManageSidebar.vue';
import ManageStatusStrip from '@/Components/Manage/ManageStatusStrip.vue';
import ToastHost from '@/Components/Manage/ToastHost.vue';

const page = usePage();

const brand = computed(() => page.props.branding?.siteName ?? 'Streaming');
</script>

<template>
  <div class="manage-root flex h-screen flex-col overflow-hidden bg-surface-0 text-fg-1 antialiased">
    <ManageStatusStrip
      :status="page.props.manageStatus"
      :brand="brand"
      :user="page.props.auth?.user"
    />

    <div class="flex min-h-0 flex-1">
      <ManageSidebar :groups="page.props.manageNav ?? []" />

      <main class="flex min-w-0 flex-1 flex-col overflow-y-auto">
        <slot />
      </main>
    </div>

    <ToastHost />
  </div>
</template>
