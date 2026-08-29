<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Logo from '@/Components/Logo.vue';
import LoginScreenWelcome from '@/Components/LoginScreenWelcome.vue';
import AuthField from '@/Components/Auth/AuthField.vue';
import AuthSubmit from '@/Components/Auth/AuthSubmit.vue';

const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
});

const submit = () => form.post(route('password.store'), {
    onFinish: () => form.reset('password', 'password_confirmation'),
});
</script>

<template>
    <GuestLayout>
        <Head>
            <title>Set a new password</title>
        </Head>

        <div class="flex flex-col gap-6">
            <Logo class="h-24 w-auto shrink-0 self-start" />

            <LoginScreenWelcome title="Set a new password" />

            <form class="flex flex-col gap-4" @submit.prevent="submit">
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
                    autofocus
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

                <AuthSubmit label="Set password" :processing="form.processing" />
            </form>
        </div>
    </GuestLayout>
</template>
