<script setup>
/**
 * Pulling the programme in from pretalx.
 *
 * Two decisions on one screen: which pretalx room streams on which channel, and which
 * sessions become shows. The mapping is saved with the import because a session can only
 * be imported once its room has a channel.
 *
 * A session that was already imported is shown but cannot be picked again; deleting its
 * show is what makes it available once more.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  configured: { type: Boolean, required: true },
  event: { type: String, default: null },
  instance: { type: String, default: null },
  error: { type: String, default: null },
  settingsUrl: { type: String, required: true },
  sources: { type: Array, default: () => [] },
  rooms: { type: Array, default: () => [] },
  slots: { type: Array, default: () => [] },
});

/** '' rather than null, because a select posts strings. */
const form = useForm({
  rooms: props.rooms.map((room) => ({
    id: room.id,
    name: room.name,
    source_id: room.source_id ?? '',
  })),
  slots: [],
});

const hidePast = ref(true);

const sourceOptions = computed(() => [
  { value: '', label: 'Not streamed' },
  ...props.sources,
]);

const sourceFor = (roomId) => {
  const room = form.rooms.find((entry) => entry.id === roomId);

  return room && room.source_id !== '' && room.source_id !== null ? room.source_id : null;
};

const sourceName = (roomId) => {
  const id = sourceFor(roomId);

  return props.sources.find((source) => String(source.value) === String(id))?.label ?? null;
};

const roomName = (roomId) =>
  props.rooms.find((room) => room.id === roomId)?.name ?? `Room ${roomId}`;

const roomSessions = (roomId) => props.rooms.find((room) => room.id === roomId)?.sessions ?? 0;

/** Why a row cannot be ticked, or null when it can. */
const blocker = (slot) => (slot.showUrl ? 'imported' : null);

const mappedRooms = computed(() => new Set(
  form.rooms.filter((room) => room.source_id !== '' && room.source_id !== null).map((room) => room.id),
));

/*
 * Only rooms with a channel are listed. A con schedules hundreds of sessions across
 * dozens of rooms and streams a handful of them; showing the rest would bury the ones
 * that matter, and they could not be imported anyway.
 */
const visible = computed(() =>
  props.slots.filter(
    (slot) => mappedRooms.value.has(slot.room_id) && (!hidePast.value || !slot.past),
  ),
);

/** Day headings, in schedule order; the server already sorted the slots. */
const days = computed(() => {
  const groups = [];

  visible.value.forEach((slot) => {
    const last = groups[groups.length - 1];

    if (last && last.label === slot.day) {
      last.slots.push(slot);

      return;
    }

    groups.push({ label: slot.day, slots: [slot] });
  });

  return groups;
});

const selectable = computed(() => visible.value.filter((slot) => !blocker(slot)));

const allSelected = computed(
  () => selectable.value.length > 0 && selectable.value.every((slot) => form.slots.includes(slot.id)),
);

const toggleAll = () => {
  form.slots = allSelected.value ? [] : selectable.value.map((slot) => slot.id);
};

const toggle = (slot) => {
  if (blocker(slot)) return;

  form.slots = form.slots.includes(slot.id)
    ? form.slots.filter((id) => id !== slot.id)
    : [...form.slots, slot.id];
};

const counts = computed(() => ({
  total: props.slots.length,
  imported: props.slots.filter((slot) => slot.showUrl).length,
  selected: form.slots.length,
}));

const submit = () => {
  form
    .transform((data) => ({
      rooms: data.rooms.map((room) => ({
        id: room.id,
        name: room.name,
        source_id: room.source_id === '' ? null : Number(room.source_id),
      })),
      // Only what is still on screen and still importable, so a stale tick from before a
      // room was unmapped cannot travel with the post.
      slots: data.slots.filter((id) => selectable.value.some((slot) => slot.id === id)),
    }))
    .post(route('manage.shows.import.store'), {
      preserveScroll: true,
      onSuccess: () => {
        form.slots = [];
      },
    });
};

const reload = () => router.post(route('manage.shows.import.refresh'), {}, { preserveScroll: true });
</script>

<template>
  <ManageLayout>
    <Head title="Import from pretalx" />

    <PageHeader
      title="Import from pretalx"
      :subtitle="configured
        ? `${event} on ${instance} · ${counts.total} sessions, ${counts.imported} already imported`
        : 'Not connected to a pretalx instance yet'"
    >
      <template #actions>
        <button
          v-if="configured"
          type="button"
          class="flex h-8 items-center gap-1.5 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
          @click="reload"
        >
          <ManageIcon name="refresh-cw" class="size-3.5" />
          Reload schedule
        </button>
        <Link
          :href="settingsUrl"
          class="flex h-8 items-center gap-1.5 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
        >
          Settings
        </Link>
      </template>
    </PageHeader>

    <div class="flex min-h-0 flex-1 flex-col">
      <div class="flex flex-col gap-4 p-4">
        <div
          v-if="!configured"
          class="rounded border border-hairline bg-surface-1 px-3 py-3 text-[13px] text-fg-2"
        >
          <p class="text-fg-1">No pretalx instance configured.</p>
          <p class="mt-1 text-[12px] text-fg-3">
            Set the instance URL and the event slug under Settings &gt; Pretalx, then come back here.
            A token is only needed while the schedule is unpublished.
          </p>
          <Link
            :href="settingsUrl"
            class="mt-2 inline-flex h-8 items-center rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3"
          >
            Open settings
          </Link>
        </div>

        <p
          v-else-if="error"
          class="rounded border border-state-danger/35 bg-state-danger/10 px-3 py-2 text-[13px] text-state-danger"
        >
          {{ error }}
        </p>

        <form v-if="configured && !error" class="flex flex-col gap-4" @submit.prevent="submit">
          <FormSection
            title="Channels"
            description="Which channel each pretalx room streams on. Rooms left unstreamed cannot be imported."
          >
            <FormField
              v-for="room in form.rooms"
              :key="room.id"
              v-model="room.source_id"
              :label="room.name"
              type="select"
              :options="sourceOptions"
              :helper="`${roomSessions(room.id)} session${roomSessions(room.id) === 1 ? '' : 's'}`"
              narrow
            />

            <p v-if="!form.rooms.length" class="py-2 text-[13px] text-fg-3">
              This event has no rooms with sessions in its published schedule.
            </p>
          </FormSection>

          <FormSection
            title="Sessions"
            :description="`${counts.selected} selected of ${selectable.length} importable`"
          >
            <div class="flex flex-wrap items-center gap-4 py-2">
              <label class="flex items-center gap-2 text-[13px] text-fg-2">
                <input v-model="hidePast" type="checkbox" class="size-4 accent-state-live" />
                Hide sessions that already ended
              </label>

              <button
                type="button"
                class="h-8 rounded border border-hairline px-3 text-[13px] text-fg-1 transition-colors hover:bg-surface-3 disabled:opacity-40"
                :disabled="!selectable.length"
                @click="toggleAll"
              >
                {{ allSelected ? 'Clear selection' : 'Select all importable' }}
              </button>
            </div>

            <div v-for="day in days" :key="day.label" class="py-2">
              <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-fg-3">
                {{ day.label }}
              </p>

              <div class="overflow-hidden rounded border border-hairline">
                <div
                  v-for="slot in day.slots"
                  :key="slot.id"
                  class="flex items-center gap-3 border-b border-hairline/40 px-3 py-2 last:border-b-0"
                  :class="blocker(slot) ? 'bg-surface-2/40' : 'cursor-pointer hover:bg-surface-2'"
                  @click="toggle(slot)"
                >
                  <input
                    type="checkbox"
                    class="size-4 shrink-0 accent-state-live"
                    :checked="form.slots.includes(slot.id)"
                    :disabled="Boolean(blocker(slot))"
                    @click.stop="toggle(slot)"
                  />

                  <span class="w-24 shrink-0 font-mono text-[12px] text-fg-3">{{ slot.time }}</span>

                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] text-fg-1">{{ slot.title }}</span>
                    <span class="block truncate text-[11px] text-fg-3">
                      {{ roomName(slot.room_id) }}
                      <template v-if="slot.speakers.length"> · {{ slot.speakers.join(', ') }}</template>
                    </span>
                  </span>

                  <span class="shrink-0 text-[11px]">
                    <Link
                      v-if="slot.showUrl"
                      :href="slot.showUrl"
                      class="text-fg-3 underline-offset-2 hover:text-fg-1 hover:underline"
                      @click.stop
                    >
                      Imported
                    </Link>
                    <span v-else class="text-fg-3">{{ sourceName(slot.room_id) }}</span>
                  </span>
                </div>
              </div>
            </div>

            <p v-if="!mappedRooms.size" class="py-2 text-[13px] text-fg-3">
              Give at least one room a channel above. Only those rooms' sessions are listed.
            </p>
            <p v-else-if="!days.length" class="py-2 text-[13px] text-fg-3">
              Nothing left in the streamed rooms. Either everything is imported, or it has already ended.
            </p>
          </FormSection>

          <FormActions
            :processing="form.processing"
            :dirty="form.isDirty"
            :submit-label="counts.selected
              ? `Import ${counts.selected} session${counts.selected === 1 ? '' : 's'}`
              : 'Save channel mapping'"
          />
        </form>
      </div>
    </div>
  </ManageLayout>
</template>
