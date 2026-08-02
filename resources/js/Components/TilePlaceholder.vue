<script setup>
/**
 * Stand-in artwork for a tile with no thumbnail.
 *
 * Diagonal stripes rather than a centred video icon: at grid scale a repeated glyph
 * reads as "broken image" on every card at once, while a stripe field reads as an
 * intentional empty slot and still gives the row a rhythm. The stripe angle and
 * spacing are fixed so a wall of placeholders lines up instead of shimmering.
 */
defineProps({
  /** Optional short text drawn over the stripes, e.g. a channel name. */
  label: { type: String, default: null },
});
</script>

<template>
  <div class="tile-placeholder">
    <div class="tile-placeholder-stripes" aria-hidden="true" />
    <span v-if="label" class="tile-placeholder-label">{{ label }}</span>
  </div>
</template>

<style scoped>
@reference "../../css/app.css";

.tile-placeholder {
  @apply relative w-full h-full overflow-hidden bg-gradient-to-br from-primary-800 to-primary-950;
}

/* Two stripe layers: wide soft bands for depth, narrow crisp lines on top. */
.tile-placeholder-stripes {
  position: absolute;
  inset: 0;
  background-image:
    repeating-linear-gradient(
      135deg,
      color-mix(in oklch, var(--color-primary-300) 12%, transparent) 0 2px,
      transparent 2px 14px
    ),
    repeating-linear-gradient(
      135deg,
      color-mix(in oklch, var(--color-primary-400) 7%, transparent) 0 10px,
      transparent 10px 42px
    );
  mask-image: linear-gradient(to bottom right, rgb(0 0 0 / 0.95), rgb(0 0 0 / 0.35));
}

/* Centred, not bottom-left: the corners belong to the live badge, viewer count
   and duration, and a label down there collides with all three. */
.tile-placeholder-label {
  @apply absolute inset-0 flex items-center justify-center px-4 text-center text-[11px] font-semibold uppercase tracking-[0.14em] text-primary-300/60;
}
</style>
