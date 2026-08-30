<script setup>
import { computed } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { BellRing, Download, KeyRound, ListChecks, Lock, Send, SlidersHorizontal, Trash2 } from 'lucide-vue-next'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Container from '@/Components/Container.vue'
import SettingsNav from '@/Components/Settings/SettingsNav.vue'
import SettingsCard from '@/Components/Settings/SettingsCard.vue'
import SettingsList from '@/Components/Settings/SettingsList.vue'
import SettingsRow from '@/Components/Settings/SettingsRow.vue'
import SettingsFooter from '@/Components/Settings/SettingsFooter.vue'
import NotificationChannels from '@/Components/Notifications/NotificationChannels.vue'
import NotificationCategories from '@/Components/Notifications/NotificationCategories.vue'
import FollowedShows from '@/Components/Notifications/FollowedShows.vue'
import DeleteAccount from '@/Components/Settings/DeleteAccount.vue'

/**
 * A viewer's own settings, one page per section.
 *
 * A menu rather than a single scroll, because this is where an account, a display name
 * and an avatar will go, and a page that is already a stack of unrelated cards has
 * nowhere to put them.
 */
const props = defineProps({
    section: { type: String, required: true },
    navigation: { type: Array, default: () => [] },
    featureSettings: { type: Array, default: () => [] },
    // Null when the installation has notifications switched off.
    notifications: { type: Object, default: null },
    // The ways into this account: one row per provider, plus whether it holds a password.
    connections: { type: Object, default: null },
    account: { type: Object, required: true },
})

const page = usePage()

const form = useForm({
    features: Object.fromEntries(props.featureSettings.map((feature) => [feature.key, feature.enabled])),
})

const status = computed(() => page.props.flash?.status ?? null)

// Emotes live inside chat, so with chat off theirs is not a choice any more.
const lockedByChat = (key) => key === 'emotes' && form.features.chat === false

function submit() {
    form.patch(route('settings.update'), { preserveScroll: true })
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

function savePassword() {
    passwordForm.put(route('settings.password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    })
}

/*
 * Disconnecting is not undoable from here - the account is still there and nothing
 * opens it - so the last one is refused on the server and asked about here.
 */
function disconnect(provider) {
    if (!window.confirm(`Disconnect ${provider.label} from this account?`)) return

    router.delete(provider.disconnectUrl, { preserveScroll: true })
}

const connectionError = computed(() => page.props.errors?.connection ?? null)
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <Container class="py-8 sm:py-10">
            <!-- Capped: a settings form stretched across a 27in monitor puts the label
                 and the control it belongs to a foot apart. -->
            <div class="mx-auto max-w-4xl">
                <h1 class="mb-6 text-2xl font-bold tracking-tight text-white sm:text-3xl">Settings</h1>

                <div class="flex flex-col gap-6 md:flex-row md:gap-8">
                    <div class="md:w-52 md:shrink-0">
                        <div class="md:sticky md:top-6">
                            <SettingsNav :navigation="navigation" :current="section" />
                        </div>
                    </div>

                    <div class="min-w-0 flex-1 space-y-5">

                        <template v-if="section === 'notifications' && notifications">
                            <SettingsCard title="Channels" :icon="Send">
                                <NotificationChannels :notifications="notifications" />
                            </SettingsCard>

                            <SettingsCard title="Categories" :icon="BellRing">
                                <NotificationCategories :notifications="notifications" />
                            </SettingsCard>

                            <SettingsCard title="Shows you follow" :icon="ListChecks">
                                <FollowedShows :shows="notifications.followedShows" />
                            </SettingsCard>
                        </template>

                        <SettingsCard v-if="section === 'features'" title="Features" :icon="SlidersHorizontal">
                            <form @submit.prevent="submit">
                                <SettingsList>
                                    <SettingsRow v-for="feature in featureSettings" :key="feature.key">
                                        <label class="flex min-w-0 flex-1 cursor-pointer items-start justify-between gap-4">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium text-primary-100">{{ feature.label }}</span>
                                                <span class="mt-0.5 block text-xs text-primary-500">{{ feature.helper }}</span>
                                                <span v-if="lockedByChat(feature.key)" class="mt-0.5 block text-xs text-primary-400">
                                                    Off while chat is off.
                                                </span>
                                            </span>

                                            <input
                                                v-model="form.features[feature.key]"
                                                type="checkbox"
                                                :disabled="lockedByChat(feature.key)"
                                                class="mt-0.5 size-4 shrink-0 accent-primary-500 disabled:opacity-40"
                                                :aria-label="feature.label"
                                            />
                                        </label>
                                    </SettingsRow>
                                </SettingsList>

                                <SettingsFooter>
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                                    >
                                        {{ form.processing ? 'Saving…' : 'Save' }}
                                    </button>
                                    <span v-if="status && !form.isDirty" class="text-xs text-primary-400">{{ status }}</span>
                                </SettingsFooter>
                            </form>
                        </SettingsCard>

                        <template v-if="section === 'connections' && connections">
                            <SettingsCard title="Sign-in" :icon="KeyRound">
                                <SettingsList>
                                    <SettingsRow v-if="connectionError" block>
                                        <span class="text-sm text-red-400">{{ connectionError }}</span>
                                    </SettingsRow>

                                    <SettingsRow v-for="provider in connections.providers" :key="provider.id">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-primary-100">{{ provider.label }}</span>
                                            <span class="mt-0.5 block text-xs text-primary-500">
                                                {{ provider.connected ? 'Connected' : 'Not connected' }}
                                            </span>
                                        </span>

                                        <button
                                            v-if="provider.connected"
                                            type="button"
                                            :disabled="!provider.canDisconnect"
                                            class="shrink-0 rounded-md border border-primary-700 px-4 py-2 text-sm font-medium text-primary-100 hover:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="disconnect(provider)"
                                        >
                                            Disconnect
                                        </button>
                                        <a
                                            v-else-if="provider.connectUrl"
                                            :href="provider.connectUrl"
                                            class="shrink-0 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500"
                                        >
                                            Connect
                                        </a>
                                    </SettingsRow>
                                </SettingsList>
                            </SettingsCard>
                        </template>

                        <template v-if="section === 'account'">
                            <SettingsCard
                                v-if="connections?.canSetPassword"
                                :title="connections.hasPassword ? 'Change password' : 'Set a password'"
                                :icon="Lock"
                            >
                                <form @submit.prevent="savePassword">
                                    <SettingsList>
                                        <SettingsRow v-if="connections.hasPassword" block>
                                            <label class="block text-sm text-primary-100" for="current_password">
                                                Current password
                                            </label>
                                            <input
                                                id="current_password"
                                                v-model="passwordForm.current_password"
                                                type="password"
                                                autocomplete="current-password"
                                                class="mt-1.5 w-full rounded-md border border-primary-700 bg-primary-950 px-3 py-2 text-sm text-primary-100 focus:border-primary-500 focus:outline-none"
                                            />
                                            <p v-if="passwordForm.errors.current_password" class="mt-1 text-xs text-red-400">
                                                {{ passwordForm.errors.current_password }}
                                            </p>
                                        </SettingsRow>

                                        <SettingsRow block>
                                            <label class="block text-sm text-primary-100" for="password">New password</label>
                                            <input
                                                id="password"
                                                v-model="passwordForm.password"
                                                type="password"
                                                autocomplete="new-password"
                                                class="mt-1.5 w-full rounded-md border border-primary-700 bg-primary-950 px-3 py-2 text-sm text-primary-100 focus:border-primary-500 focus:outline-none"
                                            />
                                            <p v-if="passwordForm.errors.password" class="mt-1 text-xs text-red-400">
                                                {{ passwordForm.errors.password }}
                                            </p>
                                        </SettingsRow>

                                        <SettingsRow block>
                                            <label class="block text-sm text-primary-100" for="password_confirmation">
                                                Repeat new password
                                            </label>
                                            <input
                                                id="password_confirmation"
                                                v-model="passwordForm.password_confirmation"
                                                type="password"
                                                autocomplete="new-password"
                                                class="mt-1.5 w-full rounded-md border border-primary-700 bg-primary-950 px-3 py-2 text-sm text-primary-100 focus:border-primary-500 focus:outline-none"
                                            />
                                        </SettingsRow>
                                    </SettingsList>

                                    <SettingsFooter>
                                        <button
                                            type="submit"
                                            :disabled="passwordForm.processing"
                                            class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                                        >
                                            {{ passwordForm.processing ? 'Saving…' : 'Save' }}
                                        </button>
                                        <span v-if="status && !passwordForm.isDirty" class="text-xs text-primary-400">{{ status }}</span>
                                    </SettingsFooter>
                                </form>
                            </SettingsCard>

                            <SettingsCard title="Your data" :icon="Download">
                                <SettingsList>
                                    <SettingsRow>
                                        <span class="text-sm text-primary-100">Download all your account data</span>

                                        <a
                                            :href="route('account.export')"
                                            class="shrink-0 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500"
                                        >
                                            Download
                                        </a>
                                    </SettingsRow>
                                </SettingsList>
                            </SettingsCard>

                            <SettingsCard title="Delete account" :icon="Trash2">
                                <DeleteAccount :name="account.name" />
                            </SettingsCard>
                        </template>

                    </div>
                </div>
            </div>
        </Container>
    </AuthenticatedLayout>
</template>
