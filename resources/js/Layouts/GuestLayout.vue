<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const branding = computed(() => page.props.branding ?? {});
const login = computed(() => branding.value.login ?? {});
const links = computed(() => branding.value.links ?? {});

// Installations that upload a still in the admin panel get it behind the
// schedule; otherwise the flat primary wash carries the column on its own.
const backgroundImage = computed(() => login.value.backgroundImage || null);

// What is on now plus what is coming up, supplied by LoginController. The rail
// is skipped entirely when nothing is scheduled.
const schedule = computed(() => page.props.schedule ?? []);

const footerLinks = computed(() => [
    { name: 'Support', href: links.value.support },
    { name: 'Legal Notice', href: links.value.imprint },
    { name: 'Privacy', href: links.value.privacy },
].filter(item => item.href));
</script>

<template>
    <div class="min-h-screen w-full flex flex-col lg:flex-row bg-primary-900 page">
        <!-- Brand side -->
        <div class="relative hidden lg:block lg:w-1/2 overflow-hidden bg-primary-800 border-r border-white/10">
            <template v-if="backgroundImage">
                <div
                    class="absolute inset-0 bg-cover bg-center"
                    :style="{ backgroundImage: `url(${backgroundImage})` }"
                />
                <!-- Flat scrim, only over an uploaded photo, so the schedule
                     stays legible. Without an image there is nothing to darken. -->
                <div class="absolute inset-0 bg-primary-900/70" />
            </template>

            <div
                v-if="schedule.length"
                class="absolute inset-0 flex items-center px-12 xl:px-20"
            >
                <div class="w-full max-w-sm">
                    <h2 class="font-mono text-xs uppercase tracking-[0.16em] text-primary-400">
                        Stream Schedule
                    </h2>

                    <ul class="mt-7 flex flex-col gap-1">
                        <li
                            v-for="item in schedule"
                            :key="item.id"
                            class="grid grid-cols-[4.25rem_1fr] items-start gap-4 rounded-lg px-3 py-3"
                            :class="item.current ? 'bg-primary-100/10' : ''"
                        >
                            <!-- On-air state is carried by the row highlight and a
                                 single dot. Repeating the word on every live row
                                 turns the rail into a wall of badges. -->
                            <span
                                class="flex items-center gap-2 font-mono text-sm tabular-nums"
                                :class="item.current ? 'text-primary-100' : 'text-primary-400'"
                            >
                                <span
                                    v-if="item.current"
                                    class="size-1.5 shrink-0 rounded-full bg-primary-100 animate-pulse motion-reduce:animate-none"
                                />
                                <span v-else class="size-1.5 shrink-0" />
                                <!-- A show that has been live since yesterday
                                     would print a start time that reads as out of
                                     order against tonight's clock times. -->
                                {{ item.current ? 'Now' : item.time }}
                            </span>

                            <span class="min-w-0">
                                <span
                                    class="block leading-snug"
                                    :class="item.current ? 'text-white font-semibold' : 'text-primary-100'"
                                >{{ item.title }}</span>
                                <span
                                    v-if="item.source"
                                    class="mt-0.5 block text-sm text-primary-400"
                                >{{ item.source }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Content side -->
        <div class="flex-1 flex flex-col px-6 py-10 sm:px-10 lg:px-16 xl:px-24">
            <div class="flex-1 flex flex-col justify-center">
                <transition name="page">
                    <div class="w-full max-w-md mx-auto lg:mx-0">
                        <slot></slot>
                    </div>
                </transition>
            </div>

            <nav aria-label="Footer" class="w-full max-w-md mx-auto lg:mx-0 pt-10">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-white/10 pt-5">
                    <a
                        v-for="item in footerLinks"
                        :key="item.name"
                        :href="item.href"
                        target="_blank"
                        rel="noopener"
                        class="text-sm text-primary-400 hover:text-primary-200 transition-colors"
                    >{{ item.name }}</a>
                </div>
            </nav>
        </div>
    </div>
</template>

<style>
.page-enter-active {
    transition: opacity .1s ease-in;
    opacity: 0;
}

.page-enter {
    opacity: 0;
}

.page-enter-to {
    opacity: 1;
}

.page * {
    transition-property: color, background-color, border-color, text-decoration-color, fill, stroke;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}
</style>
