<script setup>
/**
 * Where a viewer can be reached, and connecting Telegram if they are not yet.
 *
 * Only transports this account actually has are selectable: an address the identity
 * provider never gave us is shown as not set rather than as a box that would silently
 * never deliver.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import SettingsList from '@/Components/Settings/SettingsList.vue'
import SettingsRow from '@/Components/Settings/SettingsRow.vue'
import SettingsFooter from '@/Components/Settings/SettingsFooter.vue'

const props = defineProps({
    notifications: { type: Object, required: true },
})

const form = useForm({ channels: [...props.notifications.channels.selected] })

// Every write here re-renders the page - connecting Telegram, disconnecting it - so
// the form takes the server's answer back rather than keeping what it was built with.
watch(
    () => props.notifications.channels.selected,
    (selected) => {
        form.defaults({ channels: [...selected] })
        form.reset()
    },
)

const telegram = computed(() => props.notifications.telegram)
const available = computed(() => props.notifications.channels.available)

const copied = ref(false)
const showCode = ref(false)

const hasActions = computed(
    () => telegram.value.linked || Boolean(telegram.value.connect_url) || Boolean(telegram.value.code),
)

function toggle(channel) {
    const index = form.channels.indexOf(channel)
    if (index === -1) form.channels.push(channel)
    else form.channels.splice(index, 1)
}

function save() {
    form.patch(route('notifications.update'), { preserveScroll: true, preserveState: true })
}

function unlink() {
    router.delete(route('notifications.telegram.unlink'), { preserveScroll: true, preserveState: true })
}

function copyCode() {
    if (!telegram.value.code) return
    navigator.clipboard?.writeText(`/link ${telegram.value.code}`)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
}
</script>

<template>
    <div>
        <SettingsList>
            <SettingsRow>
                <label class="flex min-w-0 flex-1 cursor-pointer items-center justify-between gap-4">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-primary-100">Email</span>
                        <span class="mt-0.5 block truncate text-xs text-primary-500">
                            {{ notifications.email || 'Not set' }}
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        :checked="form.channels.includes('mail')"
                        :disabled="!available.includes('mail')"
                        class="size-4 shrink-0 accent-primary-500 disabled:opacity-40"
                        aria-label="Email"
                        @change="toggle('mail')"
                    />
                </label>
            </SettingsRow>

            <SettingsRow block>
                <label class="flex cursor-pointer items-center justify-between gap-4">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-primary-100">Telegram</span>
                        <span class="mt-0.5 block truncate text-xs text-primary-500">
                            <template v-if="telegram.linked">
                                {{ telegram.username ? `Connected as @${telegram.username}` : 'Connected' }}
                            </template>
                            <template v-else>Not connected</template>
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        :checked="form.channels.includes('telegram')"
                        :disabled="!available.includes('telegram')"
                        class="size-4 shrink-0 accent-primary-500 disabled:opacity-40"
                        aria-label="Telegram"
                        @change="toggle('telegram')"
                    />
                </label>

                <!-- Dropped entirely when there is nothing in it: an empty flex row still
                     carries its top margin, which read as a gap under Telegram for any
                     installation with no bot configured. -->
                <div v-if="hasActions" class="mt-2.5 flex flex-wrap items-center gap-3">
                    <button
                        v-if="telegram.linked"
                        type="button"
                        class="rounded-md border border-primary-700 px-3 py-1.5 text-xs font-medium text-primary-200 hover:border-primary-500 hover:text-primary-50"
                        @click="unlink"
                    >
                        Disconnect
                    </button>

                    <!-- One tap: Telegram opens the bot with Start already carrying the
                         code, so nothing has to be pasted. -->
                    <a
                        v-else-if="telegram.connect_url"
                        :href="telegram.connect_url"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-500"
                    >
                        Connect Telegram
                    </a>

                    <button
                        v-if="!telegram.linked && telegram.code"
                        type="button"
                        class="text-xs text-primary-400 underline underline-offset-2 hover:text-primary-200"
                        @click="showCode = !showCode"
                    >
                        Use a connect code
                    </button>
                </div>

                <div
                    v-if="!telegram.linked && telegram.code && (showCode || !telegram.connect_url)"
                    class="mt-3 rounded-lg bg-primary-950/60 p-3"
                >
                    <p class="text-xs text-primary-300">
                        Send this to
                        <a
                            v-if="telegram.bot"
                            :href="`https://t.me/${telegram.bot}`"
                            target="_blank"
                            rel="noopener"
                            class="font-medium text-primary-100 underline"
                        >@{{ telegram.bot }}</a>
                        <span v-else class="font-medium text-primary-100">the bot</span>:
                    </p>
                    <div class="mt-2 flex items-center gap-2">
                        <code class="flex-1 rounded-md bg-primary-950 px-3 py-2 font-mono text-sm text-primary-50">/link {{ telegram.code }}</code>
                        <button
                            type="button"
                            class="rounded-md border border-primary-700 px-3 py-2 text-xs font-medium text-primary-200 hover:border-primary-500 hover:text-primary-50"
                            @click="copyCode"
                        >
                            {{ copied ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                </div>
            </SettingsRow>
        </SettingsList>

        <SettingsFooter>
            <button
                type="button"
                :disabled="form.processing || !form.isDirty"
                class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                @click="save"
            >
                {{ form.processing ? 'Saving…' : 'Save' }}
            </button>
            <span v-if="!available.length" class="text-xs text-primary-400">Nowhere to send to</span>
        </SettingsFooter>
    </div>
</template>
