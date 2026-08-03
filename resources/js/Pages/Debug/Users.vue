<script setup>
import { computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import ChatBadges from '@/Components/Chat/ChatBadges.vue'

defineProps({
    users: { type: Array, default: () => [] },
    current: { type: Object, default: null },
    personas: { type: Array, default: () => [] },
    shows: { type: Array, default: () => [] },
})

const page = usePage()
const status = computed(() => page.props.flash?.status ?? null)

const post = (url) => router.post(url, {}, { preserveScroll: true })

const becomeUser = (user) => post(route('debug.login', user.id))
const spawn = (slug) => router.post(route('debug.persona'), { role: slug }, { preserveScroll: true })
</script>

<template>
    <Head title="Debug: users" />

    <div class="min-h-screen bg-primary-950 px-4 py-8 text-primary-100">
        <div class="mx-auto w-full max-w-2xl space-y-5">
            <header class="flex items-baseline justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Debug: account switcher</h1>
                    <p class="text-xs text-primary-500">Local environment only. No authentication required.</p>
                </div>
                <a :href="route('shows.grid')" class="shrink-0 text-xs text-primary-400 underline hover:text-primary-200">
                    Back to site
                </a>
            </header>

            <p v-if="status" class="rounded-md border border-emerald-800 bg-emerald-900/30 px-3 py-2 text-xs text-emerald-200">
                {{ status }}
            </p>

            <!-- Who am I -->
            <section class="rounded-lg border border-primary-800 bg-primary-900/40 p-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase tracking-widest text-primary-500">Signed in as</div>
                        <div v-if="current" class="mt-0.5 flex items-center gap-1.5">
                            <ChatBadges :badges="current.badges ?? []" />
                            <span class="truncate text-sm font-medium">{{ current.name }}</span>
                        </div>
                        <div v-else class="mt-0.5 text-sm text-primary-500">Nobody (guest)</div>
                    </div>
                    <button
                        v-if="current"
                        type="button"
                        class="shrink-0 rounded border border-primary-700 px-2 py-1 text-xs text-primary-300 hover:bg-primary-800"
                        @click="post(route('debug.logout'))"
                    >
                        Sign out
                    </button>
                </div>
            </section>

            <!-- Spawn a persona -->
            <section>
                <h2 class="mb-1.5 text-[10px] uppercase tracking-widest text-primary-500">Create and sign in</h2>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="persona in personas"
                        :key="persona.slug"
                        type="button"
                        class="rounded border border-primary-700 px-2.5 py-1.5 text-xs text-primary-200 hover:bg-primary-800"
                        @click="spawn(persona.slug)"
                    >
                        + {{ persona.label }}
                    </button>
                </div>
            </section>

            <!-- Existing users -->
            <section>
                <div class="mb-1.5 flex items-center justify-between">
                    <h2 class="text-[10px] uppercase tracking-widest text-primary-500">
                        Sign in as ({{ users.length }})
                    </h2>
                    <button
                        type="button"
                        class="text-[10px] text-red-400 hover:text-red-300"
                        @click="post(route('debug.reset'))"
                    >
                        Remove test users
                    </button>
                </div>

                <div class="divide-y divide-primary-800 overflow-hidden rounded-lg border border-primary-800">
                    <button
                        v-for="user in users"
                        :key="user.id"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left transition-colors"
                        :class="current?.id === user.id ? 'bg-primary-800/60' : 'hover:bg-primary-900'"
                        @click="becomeUser(user)"
                    >
                        <span class="flex min-w-0 items-center gap-1.5">
                            <ChatBadges :badges="user.badges ?? []" />
                            <span class="truncate text-sm" :style="{ color: user.color }">{{ user.name }}</span>
                            <span v-if="user.is_test" class="shrink-0 text-[10px] text-primary-600">test</span>
                        </span>
                        <span class="shrink-0 text-[10px] text-primary-500">
                            {{ current?.id === user.id ? 'current' : 'switch' }}
                        </span>
                    </button>
                </div>
            </section>

            <!-- Jump straight into a chat -->
            <section v-if="shows.length">
                <h2 class="mb-1.5 text-[10px] uppercase tracking-widest text-primary-500">Open a chat</h2>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="show in shows" :key="show.url">
                        <a
                            :href="show.url"
                            class="rounded border border-primary-700 px-2.5 py-1.5 text-xs text-primary-200 hover:bg-primary-800"
                        >
                            {{ show.title }}
                            <span class="text-primary-600">· {{ show.status }}</span>
                        </a>
                        <a
                            :href="show.chat_url"
                            class="rounded border border-primary-700 px-2.5 py-1.5 text-xs text-primary-400 hover:bg-primary-800"
                            target="_blank"
                        >
                            popout ↗
                        </a>
                    </template>
                </div>
            </section>

            <p class="text-[11px] leading-relaxed text-primary-600">
                Switching replaces the session in this browser. To have two people in one chat at once, open the second
                account in a private window or a different browser.
            </p>
        </div>
    </div>
</template>
