<script setup>
/**
 * A titled block of fields. One column of label/control rows by default; `columns` is
 * for the few blocks that are genuinely a row of short read-only values.
 *
 * It does not collapse. A section an operator has to open first is a click in front of
 * every field it holds, and the heading is what makes a long pane readable, not the
 * folding.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    columns: { type: Number, default: 1 },
    // A fieldset rather than opacity: every control inside leaves the tab order too.
    disabled: { type: Boolean, default: false },
});

const gridClass = {
    1: "md:grid-cols-1",
    2: "md:grid-cols-2",
    3: "md:grid-cols-3",
};
</script>

<template>
    <section class="rounded border border-hairline bg-surface-1">
        <header
            class="flex items-center gap-2 border-b border-hairline px-3 py-2"
        >
            <div class="min-w-0">
                <h2
                    class="text-[12px] font-semibold uppercase tracking-wide text-fg-1"
                >
                    {{ title }}
                </h2>
                <p v-if="description" class="text-[11px] text-fg-3">
                    {{ description }}
                </p>
            </div>
        </header>

        <fieldset
            class="min-w-0 px-3 py-2"
            :class="[
                columns > 1
                    ? ['grid grid-cols-1 gap-x-6 gap-y-1', gridClass[columns]]
                    : 'divide-y divide-hairline/40',
                disabled ? 'opacity-50' : '',
            ]"
            :disabled="disabled"
        >
            <slot />
        </fieldset>
    </section>
</template>
