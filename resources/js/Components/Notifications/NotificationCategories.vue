<script setup>
/**
 * What a viewer is told about, written as sentences.
 *
 * The checkbox switches the category on and off; the scope is a control inside the
 * sentence rather than a labelled field beside it, so the remaining choice reads as
 * English instead of as two things to hold in the head at once.
 */
import { reactive, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import SettingsList from '@/Components/Settings/SettingsList.vue'
import SettingsRow from '@/Components/Settings/SettingsRow.vue'
import SettingsFooter from '@/Components/Settings/SettingsFooter.vue'

const props = defineProps({
    notifications: { type: Object, required: true },
})

const scopesOf = (source) =>
    Object.fromEntries(source.categories.map((category) => [category.key, category.scope]))

const form = useForm({ scopes: scopesOf(props.notifications) })

// What a category goes back to when it is switched on again. Unchecking is not a
// decision about how wide it should be, so the width is remembered rather than reset.
const lastScope = reactive(
    Object.fromEntries(
        props.notifications.categories.map((category) => [
            category.key,
            category.scope === 'off' ? 'subscribed' : category.scope,
        ]),
    ),
)

watch(
    () => props.notifications.categories,
    () => {
        form.defaults({ scopes: scopesOf(props.notifications) })
        form.reset()
    },
)

const isOn = (key) => form.scopes[key] !== 'off'

function toggle(key) {
    if (isOn(key)) {
        lastScope[key] = form.scopes[key]
        form.scopes[key] = 'off'

        return
    }

    form.scopes[key] = lastScope[key] ?? 'subscribed'
}

function save() {
    form.patch(route('notifications.update'), { preserveScroll: true, preserveState: true })
}
</script>

<template>
    <div>
        <SettingsList>
            <SettingsRow v-for="category in notifications.categories" :key="category.key" block>
                <label class="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        :checked="isOn(category.key)"
                        class="mt-0.5 size-4 shrink-0 accent-primary-500"
                        :aria-label="category.label"
                        @change="toggle(category.key)"
                    />

                    <span class="text-sm leading-relaxed" :class="isOn(category.key) ? 'text-primary-100' : 'text-primary-500'">
                        {{ category.before }}
                        <span class="inline-scope" :class="{ 'inline-scope-off': !isOn(category.key) }">
                            <select
                                v-model="form.scopes[category.key]"
                                :disabled="!isOn(category.key)"
                                :aria-label="`${category.label}: which shows`"
                                @click.stop
                            >
                                <option v-for="(label, value) in notifications.scopeOptions" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                            <span class="inline-scope-face" aria-hidden="true">
                                {{ notifications.scopeOptions[form.scopes[category.key]] ?? notifications.scopeOptions[lastScope[category.key]] }}
                            </span>
                        </span>
                        {{ category.after }}
                    </span>
                </label>
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
        </SettingsFooter>
    </div>
</template>

<style scoped>
@reference "../../../css/app.css";

/* A select with none of a select's chrome: the control is the word in the sentence.
   The real <select> is stretched over the word and made invisible, so the native
   picker still opens and the keyboard still works. */
.inline-scope {
    @apply relative inline-flex items-center;
}

.inline-scope select {
    @apply absolute inset-0 h-full w-full cursor-pointer opacity-0;
}

.inline-scope select:disabled {
    @apply cursor-default;
}

.inline-scope-face {
    @apply border-b border-dotted border-primary-500 font-medium text-primary-50;
}

.inline-scope:hover .inline-scope-face,
.inline-scope:focus-within .inline-scope-face {
    @apply border-primary-300 text-white;
}

/* Switched off, the width is still shown but is not a control any more. */
.inline-scope-off .inline-scope-face,
.inline-scope-off:hover .inline-scope-face {
    @apply border-transparent text-primary-500;
}
</style>
