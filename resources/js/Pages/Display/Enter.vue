<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * Where a screen with no display session lands. The box is oversized on purpose:
 * this gets filled in from across a room, often with a TV remote, by someone who
 * read the code off a printed sheet.
 */
const form = useForm({ code: '' });
const field = ref(null);

/**
 * Mirrors EmbedKey::normalize on the way in, so the code looks the way it does on
 * the sheet while it is being typed, and the letters the alphabet leaves out
 * cannot be entered at all.
 */
const onInput = (event) => {
  const bare = event.target.value
    .toUpperCase()
    .replace(/O/g, '0')
    .replace(/[IL]/g, '1')
    .replace(/U/g, 'V')
    .replace(/[^0-9A-HJKMNP-TV-Z]/g, '')
    .slice(0, 8);

  form.code = bare.length > 4 ? `${bare.slice(0, 4)}-${bare.slice(4)}` : bare;
  event.target.value = form.code;
};

const complete = computed(() => form.code.replace('-', '').length === 8);

const submit = () => {
  form.post(route('display.redeem'), {
    onError: () => {
      form.reset('code');
      field.value?.focus();
    },
  });
};
</script>

<template>
  <Head><title>Set up this screen</title></Head>

  <div class="flex min-h-screen items-center justify-center bg-primary-950 px-6 text-center">
    <div class="w-full max-w-lg">
      <h1 class="text-3xl font-bold text-white">Set up this screen</h1>
      <p class="mt-3 text-primary-400">Enter the display code you were given.</p>

      <form class="mt-8" @submit.prevent="submit">
        <input
          ref="field"
          :value="form.code"
          type="text"
          inputmode="text"
          autocapitalize="characters"
          autocomplete="off"
          autocorrect="off"
          spellcheck="false"
          maxlength="9"
          placeholder="XXXX-XXXX"
          aria-label="Display code"
          class="w-full rounded-xl border-2 bg-primary-900/60 px-6 py-5 text-center font-mono text-4xl uppercase tracking-[0.3em] text-white placeholder-primary-700 focus:outline-none"
          :class="form.errors.code ? 'border-red-500' : 'border-primary-800 focus:border-primary-500'"
          autofocus
          @input="onInput"
        />

        <p v-if="form.errors.code" class="mt-3 text-sm text-red-400">{{ form.errors.code }}</p>

        <button
          type="submit"
          :disabled="!complete || form.processing"
          class="mt-6 w-full rounded-xl bg-primary-600 px-6 py-4 text-lg font-semibold text-white transition hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-primary-600"
        >
          {{ form.processing ? 'Checking…' : 'Start' }}
        </button>
      </form>

      <p class="mt-8 text-sm text-primary-500">
        The code is only needed once per screen. It stays signed in afterwards.
      </p>
    </div>
  </div>
</template>
