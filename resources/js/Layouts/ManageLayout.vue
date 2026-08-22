<script setup>
/**
 * Shell for the /manage panel: status strip across the top, sidebar on the left, page
 * content to the right of it.
 *
 * Dark only. The surface and state tokens are defined at :root in app.css rather than
 * behind a .dark class, so there is nothing here to toggle and no flash on first paint.
 *
 * Below lg the sidebar leaves the flow and becomes a drawer over the content, opened
 * from the strip. A phone has no 240px to spare, and the panel is used one page at a
 * time, so the nav is worth reaching for rather than keeping on screen.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ManageSidebar from '@/Components/Manage/ManageSidebar.vue';
import ManageStatusStrip from '@/Components/Manage/ManageStatusStrip.vue';
import ToastHost from '@/Components/Manage/ToastHost.vue';

const page = usePage();

const brand = computed(() => page.props.branding?.siteName ?? 'Streaming');

const navOpen = ref(false);

// A visit is the drawer's job done; leaving it open would cover the page it just opened.
watch(() => page.url, () => (navOpen.value = false));

const onKey = (event) => {
  if (event.key === 'Escape') {
    navOpen.value = false;
  }
};

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <div class="manage-root flex h-screen h-[100dvh] flex-col overflow-hidden bg-surface-0 text-fg-1 antialiased">
    <ManageStatusStrip
      :status="page.props.manageStatus"
      :brand="brand"
      :user="page.props.auth?.user"
      :nav-open="navOpen"
      @toggle-nav="navOpen = !navOpen"
    />

    <div class="relative flex min-h-0 flex-1">
      <div
        v-if="navOpen"
        class="fixed inset-0 z-40 bg-black/60 lg:hidden"
        aria-hidden="true"
        @click="navOpen = false"
      />

      <ManageSidebar
        :groups="page.props.manageNav ?? []"
        :open="navOpen"
        @close="navOpen = false"
      />

      <main class="flex min-w-0 flex-1 flex-col overflow-y-auto">
        <slot />
      </main>
    </div>

    <ToastHost />
  </div>
</template>
