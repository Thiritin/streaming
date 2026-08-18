<script setup>
import { computed, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import Logo from "@/Components/Logo.vue";

const showingNavigationDropdown = ref(false);

const page = usePage();
const branding = computed(() => page.props.branding ?? {});
const siteName = computed(() => branding.value.siteName ?? '');
// No logo uploaded means the nav mark is the site name in text, so the home
// link never collapses to nothing.
const hasLogo = computed(() => !!branding.value.logoUrl);
// Configured in /manage > Settings, any number of them. None means no link row.
const footerLinks = computed(() => branding.value.links ?? []);
// The project credit, or null when the installation has turned it off.
const source = computed(() => branding.value.source ?? null);
const logoutUrl = computed(() => branding.value.identity?.logoutUrl ?? '#');

// A signed-out visitor only reaches this layout where login is optional, so the
// user block becomes a sign-in button and the emote library, which only exists
// for chat, drops out along with chat itself.
const user = computed(() => page.props.auth?.user ?? null);
const loginUrl = computed(() => page.props.features?.loginUrl ?? '/login');
const chatEnabled = computed(() => page.props.features?.chat !== false);
const emotesEnabled = computed(() => page.props.features?.emotes !== false);

// Picture from the identity provider, stored on the user at sign-in. A dead or
// blocked URL falls back to the initial rather than a broken image.
const avatarFailed = ref(false);
const avatarUrl = computed(() => (avatarFailed.value ? null : user.value?.avatar || null));
const initial = computed(() => user.value?.name?.charAt(0)?.toUpperCase() || 'U');
</script>

<template>
  <div>
    <div class="min-h-screen bg-primary-900 flex flex-col">
      <!-- Top Navigation Bar -->
      <nav class="bg-primary-950 sticky top-0 w-full z-50 border-b border-primary-800/50">
        <!-- Same track as the page content (Container.vue), so the logo sits on the
             same left edge as the first card below it. -->
        <div class="mx-auto max-w-page px-4 sm:px-6 lg:px-8">
          <!-- Three tracks rather than justify-between: the middle one is what keeps the
               menu dead centre in the bar no matter how wide the logo or the user block get. -->
          <div class="grid h-14 grid-cols-[1fr_auto_1fr] items-center gap-4">
            <!-- Left side -->
            <div class="flex items-center gap-6">
              <!-- Logo -->
              <Link :href="route('shows.grid')" prefetch class="flex items-center gap-2 group">
                <Logo v-if="hasLogo" class="h-8 w-auto fill-current text-primary-300 group-hover:text-primary-200 transition-colors" />
                <span
                  v-else
                  class="text-base font-semibold text-primary-200 group-hover:text-white transition-colors"
                >
                  {{ siteName }}
                </span>
              </Link>
            </div>

            <!-- Navigation Links -->
            <div class="hidden sm:flex items-center justify-center gap-1">
                <NavLink :href="route('shows.grid')" :active="route().current('shows.grid')" prefetch>
                  <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                  <span>Browse</span>
                </NavLink>
                <NavLink :href="route('schedule.index')" :active="route().current('schedule.*')" prefetch>
                  <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                  <span>Schedule</span>
                </NavLink>
                <NavLink :href="route('recordings.index')" :active="route().current('recordings.*')" prefetch>
                  <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                  </svg>
                  <span>Archive</span>
                </NavLink>
                <!-- Staff and admins only; /manage is the panel that replaces /admin. -->
                <NavLink
                  v-if="$page.props.auth.can_access_manage"
                  :href="route('manage.home')"
                  :active="route().current('manage.*')"
                >
                  <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                  <span>Admin</span>
                </NavLink>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center justify-end gap-3">
              <!-- Signed out, on an installation where login is optional -->
              <a
                v-if="!user"
                :href="loginUrl"
                class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-primary-500"
              >
                Sign in
              </a>

              <!-- User Dropdown -->
              <Dropdown v-else align="right" width="48">
                <template #trigger>
                  <button
                    type="button"
                    class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-primary-800 transition-all group"
                  >
                    <!-- Avatar -->
                    <img
                      v-if="avatarUrl"
                      :src="avatarUrl"
                      alt=""
                      loading="lazy"
                      referrerpolicy="no-referrer"
                      class="w-8 h-8 rounded-full object-cover bg-primary-800 ring-2 ring-transparent group-hover:ring-primary-500/50 transition-all"
                      @error="avatarFailed = true"
                    />
                    <div v-else class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center ring-2 ring-transparent group-hover:ring-primary-500/50 transition-all">
                      <span class="text-white font-semibold text-sm">{{ initial }}</span>
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
                  </div>

                  <!-- Links -->
                  <DropdownLink :href="route('settings.edit')">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <circle cx="12" cy="12" r="3" stroke-width="2" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1.08-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z" />
                    </svg>
                    Settings
                  </DropdownLink>
                  <DropdownLink v-if="emotesEnabled" :href="route('emotes.index')">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <circle cx="12" cy="12" r="9" stroke-width="2" />
                      <path stroke-linecap="round" stroke-width="2" d="M9 10h.01M15 10h.01M8.5 14.5a4.5 4.5 0 007 0" />
                    </svg>
                    Emotes
                  </DropdownLink>
                  <DropdownLink v-if="source" as="a" :href="source.url" target="_blank">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                      <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd" />
                    </svg>
                    GitHub
                  </DropdownLink>
                  <DropdownLink as="a" :href="logoutUrl" class="text-red-400 hover:text-red-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Log Out
                  </DropdownLink>
                </template>
              </Dropdown>
            </div>

            <!-- Mobile Hamburger. Pinned to the last track: the centre and right cells are
                 display:none here, so auto-placement would otherwise drop it in the middle. -->
            <div class="col-start-3 flex items-center justify-end sm:hidden">
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
          enter-active-class="transition duration-(--dur-base) ease-(--ease-out-expo)"
          enter-from-class="opacity-0 -translate-y-2"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition duration-(--dur-fast) ease-(--ease-in-quart)"
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
              <ResponsiveNavLink :href="route('schedule.index')" :active="route().current('schedule.*')" prefetch>
                Schedule
              </ResponsiveNavLink>
              <ResponsiveNavLink :href="route('recordings.index')" :active="route().current('recordings.*')" prefetch>
                Archive
              </ResponsiveNavLink>
              <ResponsiveNavLink v-if="$page.props.auth.can_access_manage" :href="route('manage.home')" :active="route().current('manage.*')">
                Admin
              </ResponsiveNavLink>
            </div>

            <!-- Mobile User Info -->
            <div v-if="!user" class="pt-4 pb-3 border-t border-primary-800">
              <ResponsiveNavLink :href="loginUrl" as="a">
                Sign in
              </ResponsiveNavLink>
            </div>

            <div v-else class="pt-4 pb-3 border-t border-primary-800">
              <div class="flex items-center px-4 gap-3">
                <img
                  v-if="avatarUrl"
                  :src="avatarUrl"
                  alt=""
                  loading="lazy"
                  referrerpolicy="no-referrer"
                  class="w-10 h-10 rounded-full object-cover bg-primary-800"
                  @error="avatarFailed = true"
                />
                <div v-else class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
                  <span class="text-white font-semibold">{{ initial }}</span>
                </div>
                <div class="font-medium text-white">{{ $page.props.auth.user.name }}</div>
              </div>

              <div class="mt-3 space-y-1">
                <ResponsiveNavLink :href="route('settings.edit')" :active="route().current('settings.*')" prefetch>
                  Settings
                </ResponsiveNavLink>
                <ResponsiveNavLink v-if="emotesEnabled" :href="route('emotes.index')" :active="route().current('emotes.*')" prefetch>
                  Emotes
                </ResponsiveNavLink>
                <ResponsiveNavLink v-if="source" :href="source.url" as="a">
                  GitHub
                </ResponsiveNavLink>
                <ResponsiveNavLink :href="logoutUrl" as="a" class="text-red-400">
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
        <div class="px-4 sm:px-6 lg:px-8">
          <div class="flex flex-col sm:flex-row items-center justify-between gap-4 max-w-page mx-auto">
            <div class="flex items-center gap-2 text-primary-400 text-sm">
              <Logo v-if="hasLogo" class="h-5 w-auto fill-current text-primary-500" />
              <span>{{ siteName }}</span>
            </div>
            <div v-if="footerLinks.length" class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
              <a
                v-for="item in footerLinks"
                :key="item.url"
                :href="item.url"
                target="_blank"
                rel="noopener"
                class="text-sm text-primary-400 hover:text-primary-200 transition-colors"
              >{{ item.label }}</a>
            </div>

            <div class="flex flex-col items-center gap-1 text-sm text-primary-500 sm:items-end">
              <!-- Credits the software rather than the installation, which is why it is
                   not one of the branding links. Turned off in /manage > Settings. -->
              <template v-if="source">
                <span class="text-primary-500">Open source streaming system</span>
                <span class="text-primary-500">
                  <a
                    :href="source.url"
                    target="_blank"
                    rel="noopener"
                    class="hover:text-primary-300 transition-colors"
                  >Run your own</a>
                  &middot;
                  <a
                    :href="source.licenceUrl"
                    target="_blank"
                    rel="noopener"
                    class="hover:text-primary-300 transition-colors"
                  >{{ source.licence }}</a>
                </span>
              </template>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>

<style>
@reference "../../css/app.css";

/* Nav item styling lives in NavLink.vue so the desktop and mobile menus agree. */
</style>
