<template>
  <div class="status-screen">
    <!-- Backdrop. The show's own still, blurred and pushed back, so the box still
         looks like this show rather than a generic error page. Falls back to the
         same gradient the stage hero uses when there is no still. -->
    <div class="absolute inset-0" aria-hidden="true">
      <img
        v-if="backdrop"
        :src="backdrop"
        alt=""
        class="h-full w-full scale-110 object-cover opacity-35 blur-2xl"
      />
      <div class="absolute inset-0 status-wash" />
    </div>

    <div class="status-body">
      <div class="status-content">
        <p v-if="eyebrow" class="status-eyebrow">
          <span class="status-pip" :class="`status-pip-${tone}`" aria-hidden="true" />
          {{ eyebrow }}
        </p>

        <h1 class="status-title">{{ title }}</h1>

        <p v-if="subtitle" class="status-subtitle">{{ subtitle }}</p>

        <slot />

        <div v-if="$slots.actions" class="mt-6 flex flex-wrap items-center justify-center gap-3">
          <slot name="actions" />
        </div>
      </div>

      <slot name="next" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  /** Small line above the title, e.g. "Main Stage &middot; ended". */
  eyebrow: { type: String, default: null },
  title: { type: String, required: true },
  subtitle: { type: String, default: null },
  /** Colours the pip only. Nothing else on the screen is coloured by state. */
  tone: {
    type: String,
    default: 'idle',
    validator: (value) => ['idle', 'live', 'wait', 'warn', 'danger'].includes(value),
  },
  /** Show still used as the blurred backdrop. */
  backdrop: { type: String, default: null },
})
</script>

<style scoped>
@reference "../../../../../css/app.css";

/* Fills whatever height the column has left rather than holding a 16:9 box: on a
   viewport-tall layout a fixed ratio left a dead band between the screen and the
   show details under it. The floor keeps it a screen and not a strip when the page
   is short, e.g. on a phone. */
.status-screen {
  @apply relative isolate w-full flex-1 min-h-[22rem] overflow-hidden bg-primary-950 sm:min-h-[26rem];
}

.status-wash {
  background:
    radial-gradient(110% 75% at 50% 0%, color-mix(in oklab, var(--color-primary-700) 45%, transparent) 0%, transparent 65%),
    linear-gradient(to bottom, color-mix(in oklab, var(--color-primary-950) 70%, transparent) 0%, var(--color-primary-950) 100%);
}

.status-body {
  @apply absolute inset-0 flex flex-col items-center justify-center gap-6 overflow-y-auto px-6 py-8 sm:px-10;
}

.status-content {
  @apply flex max-w-xl flex-col items-center text-center;
}

.status-eyebrow {
  @apply inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-primary-300;
}

.status-title {
  @apply mt-3 text-balance text-2xl font-bold tracking-tight text-white sm:text-3xl lg:text-4xl;
}

.status-subtitle {
  @apply mt-2 text-pretty text-sm text-primary-300 sm:text-base;
}

.status-pip {
  @apply h-2 w-2 rounded-full bg-primary-400;
}

.status-pip-live {
  @apply bg-red-500;
  animation: blink 1.8s ease-in-out infinite;
}

.status-pip-wait {
  @apply bg-primary-300;
  animation: blink 2.4s ease-in-out infinite;
}

.status-pip-warn {
  @apply bg-yellow-400;
}

.status-pip-danger {
  @apply bg-red-500;
}
</style>
