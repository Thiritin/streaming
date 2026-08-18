<script setup>
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Container from '@/Components/Container.vue'

/**
 * A viewer's own switches. Only features the installation has switched on are
 * listed, so this page can never offer something that does not exist.
 */
const props = defineProps({
    featureSettings: { type: Array, default: () => [] },
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
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <Container class="py-8">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-primary-50">Settings</h1>
            </div>

            <form class="max-w-2xl rounded-xl border border-primary-800 bg-primary-950 p-4" @submit.prevent="submit">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-primary-200">Features</h2>

                <p v-if="!featureSettings.length" class="text-sm text-primary-400">
                    Nothing to switch: this installation has no optional features turned on.
                </p>

                <div v-else class="divide-y divide-primary-800">
                    <label
                        v-for="feature in featureSettings"
                        :key="feature.key"
                        class="flex cursor-pointer items-start justify-between gap-4 py-3"
                    >
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
                </div>

                <div v-if="featureSettings.length" class="mt-4 flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Saving…' : 'Save' }}
                    </button>
                    <span v-if="status && !form.isDirty" class="text-xs text-primary-400">{{ status }}</span>
                </div>
            </form>
        </Container>
    </AuthenticatedLayout>
</template>
