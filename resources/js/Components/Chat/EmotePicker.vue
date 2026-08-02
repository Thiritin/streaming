<script setup>
import 'vue3-emoji-picker/css'
import EmojiPicker from 'vue3-emoji-picker'
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

const emit = defineEmits(['select', 'close'])

const page = usePage()
const tab = ref('emotes')
const search = ref('')

const allEmotes = computed(() => page.props.chat?.emotes?.list ?? [])

const groups = computed(() => {
    const term = search.value.trim().toLowerCase()
    const matching = term ? allEmotes.value.filter((emote) => emote.name.includes(term)) : allEmotes.value

    return [
        { title: 'Favorites', emotes: matching.filter((emote) => emote.favorite) },
        { title: 'Channel emotes', emotes: matching.filter((emote) => emote.global && !emote.favorite) },
        { title: 'Your emotes', emotes: matching.filter((emote) => !emote.global && !emote.favorite) },
    ].filter((group) => group.emotes.length > 0)
})
</script>

<template>
    <div class="flex h-72 w-full flex-col overflow-hidden rounded-lg border border-primary-700 bg-primary-950 shadow-2xl">
        <div class="flex items-center gap-1 border-b border-primary-800 px-2 py-1.5">
            <button
                type="button"
                class="rounded px-2 py-1 text-xs font-medium transition-colors"
                :class="tab === 'emotes' ? 'bg-primary-800 text-primary-100' : 'text-primary-400 hover:text-primary-200'"
                @click="tab = 'emotes'"
            >
                Emotes
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-xs font-medium transition-colors"
                :class="tab === 'emoji' ? 'bg-primary-800 text-primary-100' : 'text-primary-400 hover:text-primary-200'"
                @click="tab = 'emoji'"
            >
                Emoji
            </button>
            <div class="flex-1" />
            <button
                type="button"
                class="rounded p-1 text-primary-400 hover:bg-primary-800 hover:text-primary-100"
                @click="emit('close')"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>

        <template v-if="tab === 'emotes'">
            <div class="border-b border-primary-800 p-2">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search emotes"
                    class="w-full rounded-md border border-primary-800 bg-primary-900 px-2 py-1.5 text-xs text-primary-100 placeholder:text-primary-600 focus:border-primary-500 focus:outline-none"
                />
            </div>

            <div class="flex-1 overflow-y-auto p-2">
                <div v-if="!groups.length" class="pt-6 text-center text-xs text-primary-500">
                    No emotes yet. Upload one from your profile to get started.
                </div>

                <div v-for="group in groups" :key="group.title" class="mb-3">
                    <div class="mb-1 text-[10px] uppercase tracking-widest text-primary-500">{{ group.title }}</div>
                    <div class="grid grid-cols-6 gap-1">
                        <button
                            v-for="emote in group.emotes"
                            :key="emote.id"
                            type="button"
                            :title="`:${emote.name}:`"
                            class="rounded p-1 hover:bg-primary-800"
                            @click="emit('select', `:${emote.name}:`)"
                        >
                            <img :src="emote.url" :alt="`:${emote.name}:`" class="mx-auto h-7 w-7" loading="lazy" />
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <div v-else class="flex-1 overflow-hidden">
            <EmojiPicker
                :native="true"
                :display-recent="true"
                :disable-skin-tones="true"
                theme="dark"
                class="!w-full !border-0"
                @select="(emoji) => emit('select', emoji.i)"
            />
        </div>
    </div>
</template>
