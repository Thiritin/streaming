<script setup>
/**
 * One dialog for both entry points: Feedback in the top bar, and Report a problem
 * on the player.
 *
 * The difference between them is copy and what travels with the message. Feedback
 * carries the browser; a stream report carries the browser plus what the player was
 * doing at the moment the button was pressed, which is the half that makes a report
 * actionable without a reply.
 *
 * The snapshot is taken when the dialog opens and shown in full under "What gets
 * sent", so nothing leaves a viewer's browser that they were not offered the chance
 * to read first.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogDescription, DialogHeader, DialogScrollContent, DialogTitle } from '@/Components/ui/dialog';
import { collectDiagnostics, describeDiagnostics } from '@/composables/useDiagnostics';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** 'feedback' for the top bar, 'issue' for the player. */
  type: { type: String, default: 'feedback' },
  /** The show being watched, when there is one. */
  show: { type: Object, default: null },
  /**
   * The vidstack player, or a function returning it. A function is what the player
   * page passes: the instance is rebuilt on a source change, so resolving it at open
   * time is the only way to snapshot the one currently on screen.
   */
  player: { type: [Object, Function], default: null },
  /** Anything the page knows that the browser does not, e.g. the stream's own state. */
  extra: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:open']);

const TELEGRAM_KEY = 'feedback:telegram';

// Remembered per browser so somebody who reports twice in an evening does not have
// to type their handle twice. Wrapped: a browser with storage blocked still works.
const rememberedTelegram = () => {
  try {
    return localStorage.getItem(TELEGRAM_KEY) ?? '';
  } catch {
    return '';
  }
};

const rememberTelegram = (handle) => {
  try {
    if (handle) {
      localStorage.setItem(TELEGRAM_KEY, handle);
    } else {
      localStorage.removeItem(TELEGRAM_KEY);
    }
  } catch {
    // A browser that refuses storage is not a reason to lose the report.
  }
};

const form = useForm({
  type: props.type,
  message: '',
  telegram: '',
  show_slug: null,
  url: null,
  diagnostics: {},
});

const sent = ref(false);
const showDetails = ref(false);
const diagnostics = ref({});

const isIssue = computed(() => props.type === 'issue');
const details = computed(() => describeDiagnostics(diagnostics.value));

const isOpen = computed({
  get: () => props.open,
  set: (value) => emit('update:open', value),
});

const copy = computed(() =>
  isIssue.value
    ? {
        title: 'Report a problem with this stream',
        description:
          'Tell us what you are seeing - buffering, no sound, the wrong show, a picture that will not start. Details about your browser and the player go with it so we can find the cause.',
        placeholder: 'The video stalls every few seconds, audio keeps playing.',
        submit: 'Send report',
        thanks: 'Report sent. The stream team can see it in the admin panel.',
      }
    : {
        title: 'Send feedback',
        description: 'Anything you want to tell us about the site. Details about your browser go with it.',
        placeholder: 'What worked, what did not, what you would like to see.',
        submit: 'Send feedback',
        thanks: 'Thanks - your feedback is with us.',
      },
);

const resolvePlayer = () => (typeof props.player === 'function' ? props.player() : props.player);

watch(
  () => props.open,
  (open) => {
    if (!open) {
      return;
    }

    sent.value = false;
    showDetails.value = false;
    form.clearErrors();

    // Snapshot on open, not on submit: the viewer is shown exactly the payload that
    // gets sent, and a player that recovers while they are typing does not quietly
    // rewrite the evidence for the problem they are reporting.
    diagnostics.value = collectDiagnostics({
      player: resolvePlayer(),
      show: props.show,
      extra: props.extra,
    });

    form.type = props.type;
    form.show_slug = props.show?.slug ?? null;
    form.url = window.location.href;
    form.telegram = form.telegram || rememberedTelegram();
  },
);

const submit = () => {
  form.diagnostics = diagnostics.value;

  form.post(route('feedback.store'), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      rememberTelegram(form.telegram);
      sent.value = true;
      form.reset('message');
    },
  });
};

const close = () => {
  isOpen.value = false;
};
</script>

<template>
  <Dialog v-model:open="isOpen">
    <DialogScrollContent class="border-primary-700 bg-primary-900 text-white sm:max-w-lg">
      <DialogHeader>
        <DialogTitle class="text-lg font-semibold text-white">{{ copy.title }}</DialogTitle>
        <DialogDescription class="text-sm text-primary-300">{{ copy.description }}</DialogDescription>
      </DialogHeader>

      <div v-if="sent" class="flex flex-col gap-4 py-2">
        <p class="text-sm text-primary-200">{{ copy.thanks }}</p>
        <button
          type="button"
          class="self-end rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-primary-500"
          @click="close"
        >
          Close
        </button>
      </div>

      <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
        <div v-if="isIssue && show" class="rounded-md border border-primary-700 bg-primary-800/60 px-3 py-2 text-sm">
          <span class="text-primary-400">Watching</span>
          <span class="ml-2 font-medium text-white">{{ show.title }}</span>
        </div>

        <label class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-primary-200">What happened?</span>
          <textarea
            v-model="form.message"
            rows="5"
            required
            maxlength="5000"
            :placeholder="copy.placeholder"
            class="w-full rounded-md border border-primary-700 bg-primary-950 px-3 py-2 text-sm text-white placeholder:text-primary-500 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
          ></textarea>
          <span v-if="form.errors.message" class="text-sm text-red-400">{{ form.errors.message }}</span>
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-primary-200">
            Telegram <span class="font-normal text-primary-400">(optional)</span>
          </span>
          <input
            v-model="form.telegram"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="@yourhandle"
            class="w-full rounded-md border border-primary-700 bg-primary-950 px-3 py-2 text-sm text-white placeholder:text-primary-500 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
          />
          <span class="text-xs text-primary-400">
            Only so we can ask a follow-up question about this report. Leave it empty and we will not contact you.
          </span>
          <span v-if="form.errors.telegram" class="text-sm text-red-400">{{ form.errors.telegram }}</span>
        </label>

        <div class="rounded-md border border-primary-800 bg-primary-950/60">
          <button
            type="button"
            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-primary-300 transition-colors hover:text-white"
            @click="showDetails = !showDetails"
          >
            <span>What gets sent with this</span>
            <svg
              class="h-4 w-4 transition-transform"
              :class="showDetails ? 'rotate-180' : ''"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <div v-if="showDetails" class="max-h-56 overflow-y-auto border-t border-primary-800 px-3 py-2">
            <div v-for="group in details" :key="group.group" class="mb-3 last:mb-0">
              <p class="text-xs font-semibold uppercase tracking-wide text-primary-400">{{ group.group }}</p>
              <dl class="mt-1 grid grid-cols-[max-content_1fr] gap-x-3 gap-y-0.5">
                <template v-for="row in group.rows" :key="`${group.group}-${row.label}`">
                  <dt class="text-xs text-primary-400">{{ row.label }}</dt>
                  <dd class="truncate text-xs text-primary-200" :title="row.value">{{ row.value }}</dd>
                </template>
              </dl>
            </div>
            <p v-if="!details.length" class="text-xs text-primary-400">Nothing your browser would tell us.</p>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2">
          <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm text-primary-300 transition-colors hover:text-white"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="form.processing || !form.message.trim()"
            class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {{ form.processing ? 'Sending...' : copy.submit }}
          </button>
        </div>
      </form>
    </DialogScrollContent>
  </Dialog>
</template>
