<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from '@/Components/Logo.vue';
import LoginScreenWelcome from '@/Components/LoginScreenWelcome.vue';
import AuthField from '@/Components/Auth/AuthField.vue';
import AuthSubmit from '@/Components/Auth/AuthSubmit.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
});

const submit = () => form.post(route('password.email'));
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Reset password</title>
        </Head>

        <div class="flex flex-col gap-6">
            <Logo class="h-24 w-auto shrink-0 self-start" />

            <LoginScreenWelcome title="Reset password" />

            <p
                v-if="status"
                role="status"
                class="rounded-lg border border-primary-400/40 bg-primary-500/10 px-4 py-3 text-sm text-primary-200"
            >
                {{ status }}
            </p>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <AuthField
                    id="email"
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    autofocus
                    :error="form.errors.email"
                />

                <AuthSubmit label="Send reset link" :processing="form.processing" />
            </form>

            <Link
                :href="route('login')"
                class="text-sm text-primary-400 underline underline-offset-4 transition-colors hover:text-primary-200"
            >Sign in</Link>
        </div>
    </GuestLayout>
</template>
