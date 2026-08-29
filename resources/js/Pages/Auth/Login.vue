<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from '@/Components/Logo.vue';
import LoginScreenWelcome from '@/Components/LoginScreenWelcome.vue';
import AuthField from '@/Components/Auth/AuthField.vue';
import AuthSubmit from '@/Components/Auth/AuthSubmit.vue';

const props = defineProps({
    // Which ways in this installation offers; every combination is valid, including none.
    modes: { type: Object, required: true },
    status: { type: String, default: null },
});

const page = usePage();

const branding = computed(() => page.props.branding ?? {});
const login = computed(() => branding.value.login ?? {});
const identity = computed(() => branding.value.identity ?? {});

// Set when a sign-in round trip came back broken. Without this the callback's only
// options are a silent redirect or a loop back into the provider.
const error = computed(() => page.props.errors?.oidc ?? null);

// Two buttons need telling apart by what they do; one does not.
const oidcLabel = computed(() => (props.modes.local && identity.value.name
    ? `Continue with ${identity.value.name}`
    : login.value.buttonLabel));

const oidcClass = computed(() => (props.modes.local
    ? 'border border-white/15 px-6 py-3 text-lg text-primary-100 hover:bg-white/5'
    : 'bg-primary-500 px-12 py-3 text-2xl text-white hover:bg-primary-400'));

// With no way to sign in above it there is nothing else to press, and a page whose
// only action is a trailing link reads as unfinished.
const guestClass = computed(() => (props.modes.local || props.modes.oidc
    ? 'text-sm text-primary-400 underline underline-offset-4 hover:text-primary-200'
    : 'inline-flex items-center justify-center rounded-lg bg-primary-500 px-6 py-3 text-lg font-semibold text-white hover:bg-primary-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-primary-900'));

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => form.post(route('login.store'), {
    onFinish: () => form.reset('password'),
});
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
                v-if="status"
                role="status"
                class="rounded-lg border border-primary-400/40 bg-primary-500/10 px-4 py-3 text-sm text-primary-200"
            >
                {{ status }}
            </p>

            <p
                v-if="error"
                role="alert"
                class="rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200"
            >
                {{ error }}
            </p>

            <form v-if="modes.local" class="flex flex-col gap-4" @submit.prevent="submit">
                <AuthField
                    id="email"
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    autofocus
                    :error="form.errors.email"
                />

                <AuthField
                    id="password"
                    v-model="form.password"
                    label="Password"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.password"
                />

                <label class="flex items-center gap-2 text-sm text-primary-300">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="size-4 rounded border-white/20 bg-primary-950/40 text-primary-500 focus:ring-primary-300"
                    />
                    Remember me
                </label>

                <AuthSubmit label="Sign in" :processing="form.processing" />

                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <Link
                        v-if="modes.registration"
                        :href="route('register')"
                        class="text-primary-200 underline underline-offset-4 transition-colors hover:text-white"
                    >Create an account</Link>

                    <Link
                        :href="route('password.request')"
                        class="text-primary-400 underline underline-offset-4 transition-colors hover:text-primary-200"
                    >Forgot your password?</Link>
                </div>
            </form>

            <div v-if="modes.local && modes.oidc" class="flex items-center gap-4">
                <span class="h-px flex-1 bg-white/10" />
                <span class="text-xs uppercase tracking-widest text-primary-400">or</span>
                <span class="h-px flex-1 bg-white/10" />
            </div>

            <div v-if="modes.oidc" class="flex flex-col gap-3">
                <a
                    :href="route('auth.login')"
                    class="inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors duration-(--dur-base) focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-primary-900"
                    :class="oidcClass"
                >
                    {{ oidcLabel }}
                    <span v-if="!modes.local && identity.name" class="sr-only">with {{ identity.name }}</span>
                </a>

                <p v-if="identity.registerUrl" class="text-sm text-primary-400">
                    No {{ identity.name }} account yet?
                    <a
                        :href="identity.registerUrl"
                        target="_blank"
                        rel="noopener"
                        class="text-primary-200 underline underline-offset-4 hover:text-white transition-colors"
                    >Create one for free</a>.
                </p>
            </div>

            <!-- Only where nobody can get in at all. Guest access on its own is a
                 configuration rather than a fault, and its link says the whole of it. -->
            <p v-if="!modes.local && !modes.oidc && !modes.guest" class="text-primary-300">
                Sign-in is not configured.
            </p>

            <Link
                v-if="modes.guest"
                :href="route('shows.grid')"
                class="transition-colors duration-(--dur-base)"
                :class="guestClass"
            >Continue without signing in</Link>
        </div>
    </GuestLayout>
</template>
