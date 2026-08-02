<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Container from '@/Components/Container.vue'

const props = defineProps({
    userEmotes: { type: Array, default: () => [] },
    globalEmotes: { type: Array, default: () => [] },
    favoriteEmotes: { type: Array, default: () => [] },
    statistics: { type: Object, default: () => ({}) },
})

const form = useForm({
    name: '',
    image: null,
    is_global: false,
})

const preview = ref(null)
const favorites = ref(new Set(props.favoriteEmotes.map((emote) => emote.id)))

const pending = computed(() => props.userEmotes.filter((emote) => !emote.is_approved))
const approved = computed(() => props.userEmotes.filter((emote) => emote.is_approved))

function pickFile(event) {
    const file = event.target.files?.[0] ?? null

    form.image = file
    preview.value = file ? URL.createObjectURL(file) : null
}

function submit() {
    form.post(route('emotes.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.reset()
            preview.value = null
        },
    })
}

async function toggleFavorite(emote) {
    const { data } = await window.axios.post(route('emotes.favorite', emote.id))

    if (data.is_favorited) {
        favorites.value.add(emote.id)
    } else {
        favorites.value.delete(emote.id)
    }

    favorites.value = new Set(favorites.value)
}

function destroy(emote) {
    router.delete(route('emotes.destroy', emote.id), { preserveScroll: true })
}
</script>

<template>
    <Head title="Emotes" />

    <AuthenticatedLayout>
        <Container class="py-8">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-primary-50">Emotes</h1>
                <p class="mt-1 text-sm text-primary-400">
                    Type <code class="rounded bg-primary-900 px-1">:name:</code> in chat to use an emote. The picker next
                    to the message box lists everything you can send.
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Upload -->
                <div class="rounded-xl border border-primary-800 bg-primary-950 p-4">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-primary-200">Upload an emote</h2>

                    <form class="space-y-3" @submit.prevent="submit">
                        <div>
                            <label class="mb-1 block text-xs text-primary-400">Name</label>
                            <div class="flex items-center gap-1">
                                <span class="text-primary-500">:</span>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    maxlength="20"
                                    placeholder="wave"
                                    class="w-full rounded-md border border-primary-800 bg-primary-900 px-2 py-1.5 text-sm text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none"
                                />
                                <span class="text-primary-500">:</span>
                            </div>
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                            <p class="mt-1 text-xs text-primary-600">Lowercase letters, numbers and underscores.</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs text-primary-400">Image</label>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/gif,image/webp"
                                class="w-full text-xs text-primary-300 file:mr-2 file:rounded-md file:border-0 file:bg-primary-800 file:px-2 file:py-1.5 file:text-primary-100"
                                @change="pickFile"
                            />
                            <p v-if="form.errors.image" class="mt-1 text-xs text-red-400">{{ form.errors.image }}</p>
                            <p class="mt-1 text-xs text-primary-600">Max 500 KB, resized to 64×64.</p>
                        </div>

                        <img v-if="preview" :src="preview" alt="Preview" class="h-16 w-16 rounded bg-primary-900 p-1" />

                        <label class="flex items-center gap-2 text-xs text-primary-300">
                            <input v-model="form.is_global" type="checkbox" class="rounded border-primary-700 bg-primary-900" />
                            Suggest for everyone (needs approval)
                        </label>

                        <button
                            type="submit"
                            :disabled="form.processing || !form.name || !form.image"
                            class="w-full rounded-md bg-primary-600 py-2 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Uploading…' : 'Upload emote' }}
                        </button>
                        <p class="text-xs text-primary-600">
                            Uploads are reviewed by a moderator before they can be used in chat.
                        </p>
                    </form>
                </div>

                <!-- Emote lists -->
                <div class="space-y-6 lg:col-span-2">
                    <section class="rounded-xl border border-primary-800 bg-primary-950 p-4">
                        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-primary-200">
                            Channel emotes
                            <span class="ml-1 font-normal text-primary-500">({{ globalEmotes.length }})</span>
                        </h2>

                        <p v-if="!globalEmotes.length" class="text-sm text-primary-500">No channel emotes yet.</p>

                        <div v-else class="grid grid-cols-4 gap-3 sm:grid-cols-6 md:grid-cols-8">
                            <button
                                v-for="emote in globalEmotes"
                                :key="emote.id"
                                type="button"
                                class="group rounded-lg p-2 text-center hover:bg-primary-900"
                                :title="favorites.has(emote.id) ? 'Remove from favorites' : 'Add to favorites'"
                                @click="toggleFavorite(emote)"
                            >
                                <img :src="emote.url" :alt="`:${emote.name}:`" class="mx-auto h-10 w-10" loading="lazy" />
                                <div class="mt-1 truncate text-[10px] text-primary-400">:{{ emote.name }}:</div>
                                <div class="text-[10px]" :class="favorites.has(emote.id) ? 'text-amber-300' : 'text-primary-700'">
                                    ★
                                </div>
                            </button>
                        </div>
                    </section>

                    <section class="rounded-xl border border-primary-800 bg-primary-950 p-4">
                        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-primary-200">Your emotes</h2>

                        <p v-if="!userEmotes.length" class="text-sm text-primary-500">
                            You have not uploaded any emotes yet.
                        </p>

                        <div v-if="approved.length" class="mb-4">
                            <div class="mb-2 text-[10px] uppercase tracking-widest text-primary-500">Approved</div>
                            <div class="grid grid-cols-4 gap-3 sm:grid-cols-6 md:grid-cols-8">
                                <div v-for="emote in approved" :key="emote.id" class="rounded-lg p-2 text-center">
                                    <img :src="emote.url" :alt="`:${emote.name}:`" class="mx-auto h-10 w-10" loading="lazy" />
                                    <div class="mt-1 truncate text-[10px] text-primary-400">:{{ emote.name }}:</div>
                                </div>
                            </div>
                        </div>

                        <div v-if="pending.length">
                            <div class="mb-2 text-[10px] uppercase tracking-widest text-primary-500">
                                Waiting for approval
                            </div>
                            <div class="grid grid-cols-4 gap-3 sm:grid-cols-6 md:grid-cols-8">
                                <div v-for="emote in pending" :key="emote.id" class="rounded-lg p-2 text-center">
                                    <img
                                        :src="emote.url"
                                        :alt="`:${emote.name}:`"
                                        class="mx-auto h-10 w-10 opacity-60"
                                        loading="lazy"
                                    />
                                    <div class="mt-1 truncate text-[10px] text-primary-400">:{{ emote.name }}:</div>
                                    <button
                                        type="button"
                                        class="text-[10px] text-red-400 hover:text-red-300"
                                        @click="destroy(emote)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </Container>
    </AuthenticatedLayout>
</template>
