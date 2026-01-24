<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';
import Logo from "@/Components/Logo.vue";

const showingNavigationDropdown = ref(false);
</script>

<template>
  <div>
    <div class="min-h-screen bg-primary-900 flex flex-col">
      <!-- Top Navigation Bar -->
      <nav class="bg-primary-950/80 backdrop-blur-md sticky top-0 w-full z-50 border-b border-primary-800/50">
        <div class="px-4 lg:px-6">
          <div class="flex justify-between h-14">
            <!-- Left side -->
            <div class="flex items-center gap-6">
              <!-- Logo -->
              <Link :href="route('shows.grid')" prefetch class="flex items-center gap-2 group">
                <Logo class="h-8 w-auto fill-current text-primary-300 group-hover:text-primary-200 transition-colors" />
              </Link>

              <!-- Navigation Links -->
              <div class="hidden sm:flex items-center gap-1">
                <NavLink :href="route('shows.grid')" :active="route().current('shows.grid')" prefetch class="nav-item">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                  <span>Browse</span>
                </NavLink>
                <NavLink :href="route('recordings.index')" :active="route().current('recordings.*')" prefetch class="nav-item">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                  </svg>
                  <span>Recordings</span>
                </NavLink>
              </div>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center gap-3">
              <!-- Admin Link -->
              <Link
                v-if="$page.props.auth.can_access_filament"
                :href="route('filament.admin.pages.dashboard')"
                class="flex items-center gap-2 px-3 py-1.5 text-sm text-primary-400 hover:text-white hover:bg-primary-800 rounded-md transition-all"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Admin
              </Link>

              <!-- User Dropdown -->
              <Dropdown align="right" width="48">
                <template #trigger>
                  <button
                    type="button"
                    class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-primary-800 transition-all group"
                  >
                    <!-- Avatar -->
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center ring-2 ring-transparent group-hover:ring-primary-500/50 transition-all">
                      <span class="text-white font-semibold text-sm">
                        {{ $page.props.auth.user.name?.charAt(0)?.toUpperCase() || 'U' }}
                      </span>
                    </div>
                    <svg class="w-4 h-4 text-primary-400 group-hover:text-white transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                  </button>
                </template>

                <template #content>
                  <!-- User Info -->
                  <div class="px-4 py-3 border-b border-primary-700">
                    <p class="text-sm font-medium text-white">{{ $page.props.auth.user.name }}</p>
                    <p class="text-xs text-primary-400 truncate">{{ $page.props.auth.user.email }}</p>
                  </div>

                  <!-- Links -->
                  <DropdownLink as="a" href="https://github.com/Thiritin/streaming" target="_blank">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                      <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd" />
                    </svg>
                    GitHub
                  </DropdownLink>
                  <DropdownLink as="a" href="https://identity.eurofurence.org/oauth2/sessions/logout" class="text-red-400 hover:text-red-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Log Out
                  </DropdownLink>
                </template>
              </Dropdown>
            </div>

            <!-- Mobile Hamburger -->
            <div class="flex items-center sm:hidden">
              <button
                @click="showingNavigationDropdown = !showingNavigationDropdown"
                class="inline-flex items-center justify-center p-2 rounded-md text-primary-400 hover:text-white hover:bg-primary-800 focus:outline-none transition-all"
              >
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                  <path
                    :class="{
                      'hidden': showingNavigationDropdown,
                      'inline-flex': !showingNavigationDropdown,
                    }"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 6h16M4 12h16M4 18h16"
                  />
                  <path
                    :class="{
                      'hidden': !showingNavigationDropdown,
                      'inline-flex': showingNavigationDropdown,
                    }"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Mobile Navigation Menu -->
        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="opacity-0 -translate-y-2"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-2"
        >
          <div
            v-show="showingNavigationDropdown"
            class="sm:hidden bg-primary-900 border-t border-primary-800"
          >
            <div class="pt-2 pb-3 space-y-1">
              <ResponsiveNavLink :href="route('shows.grid')" :active="route().current('shows.grid')" prefetch>
                Browse
              </ResponsiveNavLink>
              <ResponsiveNavLink :href="route('recordings.index')" :active="route().current('recordings.*')" prefetch>
                Recordings
              </ResponsiveNavLink>
              <ResponsiveNavLink component="a" v-if="$page.props.auth.user.is_admin" :href="route('filament.admin.pages.dashboard')">
                Admin
              </ResponsiveNavLink>
            </div>

            <!-- Mobile User Info -->
            <div class="pt-4 pb-3 border-t border-primary-800">
              <div class="flex items-center px-4 gap-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
                  <span class="text-white font-semibold">
                    {{ $page.props.auth.user.name?.charAt(0)?.toUpperCase() || 'U' }}
                  </span>
                </div>
                <div>
                  <div class="font-medium text-white">{{ $page.props.auth.user.name }}</div>
                  <div class="text-sm text-primary-400">{{ $page.props.auth.user.email }}</div>
                </div>
              </div>

              <div class="mt-3 space-y-1">
                <ResponsiveNavLink href="https://github.com/Thiritin/streaming" as="a">
                  GitHub
                </ResponsiveNavLink>
                <ResponsiveNavLink href="https://identity.eurofurence.org/oauth2/sessions/logout" as="a" class="text-red-400">
                  Log Out
                </ResponsiveNavLink>
              </div>
            </div>
          </div>
        </Transition>
      </nav>

      <!-- Page Content -->
      <main class="flex-1">
        <slot />
      </main>

      <!-- Footer -->
      <footer class="border-t border-primary-800/50 bg-primary-950/50 py-6 mt-auto">
        <div class="px-4 lg:px-6">
          <div class="flex flex-col sm:flex-row items-center justify-between gap-4 max-w-7xl mx-auto">
            <div class="flex items-center gap-2 text-primary-400 text-sm">
              <Logo class="h-5 w-auto fill-current text-primary-500" />
              <span>Eurofurence Streaming</span>
            </div>
            <div class="text-primary-500 text-sm">
              Made with <span class="text-red-500">&#9829;</span> by the Video Team
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>

<style>
@reference "../../css/app.css";

.nav-item {
  @apply flex items-center gap-2 px-3 py-2 text-sm font-medium text-primary-300 hover:text-white hover:bg-primary-800 rounded-md transition-all;
}

.nav-item.active {
  @apply text-white bg-primary-800;
}
</style>
