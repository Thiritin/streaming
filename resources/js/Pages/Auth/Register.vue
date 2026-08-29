<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from '@/Components/Logo.vue';
import LoginScreenWelcome from '@/Components/LoginScreenWelcome.vue';
import AuthField from '@/Components/Auth/AuthField.vue';
import AuthSubmit from '@/Components/Auth/AuthSubmit.vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => form.post(route('register.store'), {
    onFinish: () => form.reset('password', 'password_confirmation'),
});
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Create an account</title>
        </Head>

        <div class="flex flex-col gap-6">
            <Logo class="h-24 w-auto shrink-0 self-start" />

            <LoginScreenWelcome title="Create an account" />

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <AuthField
                    id="name"
                    v-model="form.name"
                    label="Name"
                    autocomplete="name"
                    autofocus
                    :error="form.errors.name"
                />

                <AuthField
                    id="email"
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    :error="form.errors.email"
                />

                <AuthField
                    id="password"
                    v-model="form.password"
                    label="Password"
                    type="password"
                    autocomplete="new-password"
                    :error="form.errors.password"
                />

                <AuthField
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    label="Confirm password"
                    type="password"
                    autocomplete="new-password"
                    :error="form.errors.password_confirmation"
                />

                <AuthSubmit label="Create account" :processing="form.processing" />
            </form>

            <Link
                :href="route('login')"
                class="text-sm text-primary-400 underline underline-offset-4 transition-colors hover:text-primary-200"
            >Sign in</Link>
        </div>
    </GuestLayout>
</template>
