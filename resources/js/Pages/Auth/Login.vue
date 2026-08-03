<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from '@/Components/Logo.vue';
import LoginScreenWelcome from '@/Components/LoginScreenWelcome.vue';

const page = usePage();

const branding = computed(() => page.props.branding ?? {});
const login = computed(() => branding.value.login ?? {});
const identity = computed(() => branding.value.identity ?? {});

// Set when a sign-in round trip came back broken. Without this the callback's only
// options are a silent redirect or a loop back into the provider.
const error = computed(() => page.props.errors?.oidc ?? null);
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Sign in</title>
        </Head>

        <div class="flex flex-col gap-6">
            <!-- self-start keeps the mark at its own size; the parent is a flex
                 column, which would otherwise stretch it to the full width. -->
            <Logo class="h-24 w-auto shrink-0 self-start" />

            <LoginScreenWelcome :title="login.headline" />

            <p v-if="login.body" class="text-primary-200/90 leading-relaxed">
                {{ login.body }}
            </p>

            <p
                v-if="error"
                role="alert"
                class="rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200"
            >
                {{ error }}
            </p>

            <div class="flex flex-col gap-3">
                <a
                    :href="route('auth.login')"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary-500 px-12 py-3 text-2xl font-semibold text-white transition-colors duration-(--dur-base) hover:bg-primary-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-primary-900"
                >
                    {{ login.buttonLabel }}
                    <span v-if="identity.name" class="sr-only">with {{ identity.name }}</span>
                </a>

                <p class="text-sm text-primary-400">
                    <template v-if="identity.registerUrl">
                        No {{ identity.name }} account yet?
                        <a
                            :href="identity.registerUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-primary-200 underline underline-offset-4 hover:text-white transition-colors"
                        >Create one for free</a>.
                    </template>
                    <template v-else>
                        Sign in with your {{ identity.name }} account.
                    </template>
                </p>
            </div>
        </div>
    </GuestLayout>
</template>
