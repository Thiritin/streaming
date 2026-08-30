<script setup>
/**
 * Closing the account.
 *
 * Behind a dialog that asks for the account name typed out, rather than a password: an
 * account the identity provider owns has none. The name is checked again on the
 * server, so the button being enabled is a convenience and not the gate.
 *
 * Styled with the palette rather than through DangerButton and Modal: those render the
 * shadcn defaults, whose bg-background and bg-destructive are not tokens this
 * installation defines, so a danger button came out white on a white sheet.
 */
import { nextTick, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog'
import SettingsList from '@/Components/Settings/SettingsList.vue'
import SettingsRow from '@/Components/Settings/SettingsRow.vue'

const props = defineProps({
    name: { type: String, required: true },
})

const open = ref(false)
const input = ref(null)

const form = useForm({ confirmation: '' })

watch(open, (isOpen) => {
    if (isOpen) {
        nextTick(() => input.value?.focus())

        return
    }

    form.reset()
    form.clearErrors()
})

function submit() {
    if (form.confirmation.trim() !== props.name) {
        return
    }

    form.delete(route('account.destroy'), {
        preserveScroll: true,
        onError: () => input.value?.focus(),
    })
}
</script>

<template>
    <SettingsList>
        <SettingsRow>
            <span class="text-sm text-primary-100">
                Delete your account and all data attached to it
            </span>

            <button
                type="button"
                class="shrink-0 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-500"
                @click="open = true"
            >
                Delete account
            </button>
        </SettingsRow>
    </SettingsList>

    <Dialog v-model:open="open">
        <DialogContent class="border-primary-800 bg-primary-950 text-primary-100">
            <DialogHeader>
                <DialogTitle class="text-base text-primary-50">Delete account</DialogTitle>
                <DialogDescription class="text-sm text-primary-300">
                    Type <span class="font-medium text-primary-100">{{ name }}</span> to confirm.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submit">
                <input
                    ref="input"
                    v-model="form.confirmation"
                    type="text"
                    autocomplete="off"
                    :placeholder="name"
                    class="h-9 w-full rounded-md border border-primary-700 bg-primary-900 px-3 text-sm text-primary-50 placeholder:text-primary-500 focus:border-primary-500 focus:outline-none"
                />

                <p v-if="form.errors.confirmation" class="mt-2 text-sm text-red-400">
                    {{ form.errors.confirmation }}
                </p>
            </form>

            <DialogFooter>
                <button
                    type="button"
                    class="inline-flex h-9 items-center rounded-md border border-primary-700 px-4 text-sm text-primary-200 transition-colors hover:bg-primary-900"
                    @click="open = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="inline-flex h-9 items-center rounded-md bg-red-600 px-4 text-sm font-medium text-white transition-colors hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="form.processing || form.confirmation.trim() !== name"
                    @click="submit"
                >
                    {{ form.processing ? 'Deleting…' : 'Delete account' }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
