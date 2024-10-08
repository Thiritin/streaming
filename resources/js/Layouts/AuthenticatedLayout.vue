<script setup>
import {ref} from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import {Link} from '@inertiajs/vue3';
import Logo from "@/Components/Logo.vue";

const showingNavigationDropdown = ref(false);
</script>

<template>
  <div>
    <div class="min-h-screen bg-primary-900">
      <nav class="bg-primary-800 absolute top-0 w-full z-50">
        <!-- Primary Navigation Menu -->
        <div class="mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex justify-between h-12">
            <div class="flex">
              <!-- Logo -->
              <div class="shrink-0 flex items-center">
                <Link :href="route('dashboard')">
                  <Logo
                      class="block h-9 w-auto fill-current text-primary-200"
                  />
                </Link>
              </div>

              <!-- Navigation Links -->
              <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                  Webstream
                </NavLink>
                <NavLink :href="route('external-stream')" :active="route().current('external-stream')">
                  External Stream (VLC)
                </NavLink>
                <NavLink component="a" v-if="$page.props.auth.can_access_filament"
                         :href="route('filament.admin.pages.dashboard')">
                  Admin
                </NavLink>
              </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ml-6">
              <!-- Settings Dropdown -->
              <div class="ml-3 relative">
                <Dropdown align="right" width="48">
                  <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-primary-400 bg-primary-800 hover:text-primary-300 focus:outline-none transition ease-in-out duration-150"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="ml-2 -mr-0.5 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                  </template>

                  <template #content>
                    <!-- GitHub -->
                    <DropdownLink :as="Link" href="https://github.com/Thiritin/streaming" target="_blank">
                      View our GitHub
                    </DropdownLink>
                    <!-- Authentication -->
                    <DropdownLink :as="Link" href="https://identity.eurofurence.org/oauth2/sessions/logout">
                      Log Out
                    </DropdownLink>
                  </template>
                </Dropdown>
              </div>
            </div>

            <!-- Hamburger -->
            <div class="-mr-2 flex items-center sm:hidden">
              <button
                  @click="showingNavigationDropdown = !showingNavigationDropdown"
                  class="inline-flex items-center justify-center p-2 rounded-md text-primary-500 hover:text-primary-400 hover:bg-primary-900 focus:outline-none focus:bg-primary-900 focus:text-primary-400 transition duration-150 ease-in-out"
              >
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                  <path
                      :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex': !showingNavigationDropdown,
                                        }"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M4 6h16M4 12h16M4 18h16"
                  />
                  <path
                      :class="{
                                            hidden: !showingNavigationDropdown,
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

        <!-- Responsive Navigation Menu -->
        <div
            :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
            class="sm:hidden z-50 relative"
        >
          <div class="pt-2 pb-3 space-y-1 z-50">
            <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
              Stream Online
            </ResponsiveNavLink>
            <ResponsiveNavLink :href="route('external-stream')" :active="route().current('external-stream')">
              Stream via VLC
            </ResponsiveNavLink>
            <ResponsiveNavLink component="a" v-if="$page.props.auth.user.is_admin"
                               :href="route('filament.admin.pages.dashboard')">
              Admin
            </ResponsiveNavLink>
          </div>

          <!-- Responsive Settings Options -->
          <div class="pt-4 pb-1 border-t border-primary-600">
            <div class="px-4">
              <div class="font-medium text-base text-primary-200">
                {{ $page.props.auth.user.name }}
              </div>
              <div class="font-medium text-sm text-primary-500">{{ $page.props.auth.user.email }}</div>
            </div>

            <div class="mt-3 space-y-1">
              <!-- GitHub Link -->
              <ResponsiveNavLink href="https://github.com/Thiritin/streaming" as="a">
                View our GitHub
              </ResponsiveNavLink>
              <!-- Authentication -->
              <ResponsiveNavLink href="https://identity.eurofurence.org/oauth2/sessions/logout" as="a">
                Log Out
              </ResponsiveNavLink>
            </div>
          </div>
        </div>
      </nav>

      <!-- Page Content -->
      <main>
        <slot/>
      </main>
    </div>
  </div>
</template>
