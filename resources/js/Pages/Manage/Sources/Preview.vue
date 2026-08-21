<script setup>
/**
 * What is actually arriving on a source, watched from inside the panel.
 *
 * The public player only appears for a live show on an online source, which is no use
 * to whoever is wiring a stage up: they need the picture before any of that exists. The
 * playlist here is asked for with `preview=1`, which HlsController accepts from an
 * operator and which keeps the check out of the source's viewer count.
 */
import { computed, ref } from 'vue';
import { Deferred, Head, Link, router, usePoll } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CopyableText from '@/Components/Manage/CopyableText.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import StatusBadge from '@/Components/Manage/StatusBadge.vue';
import { resolve, toneDot } from '@/Components/Manage/tones.js';
import StreamPlayer from '@/Components/Livestream/StreamPlayer.vue';

const props = defineProps({
  sources: { type: Array, default: () => [] },
  selected: { type: Object, default: null },
  probe: { type: Object, default: null },
});

/*
 * The probe and the source list, never the page: a full visit would tear the player
 * down every 15 seconds. The list is in because the dots on the buttons go stale
 * otherwise - a source flips online when an encoder connects, with nothing on this
 * page to cause it.
 */
usePoll(15000, { only: ['probe', 'sources', 'selected'] });

/*
 * Bumped on every switch and reload so the player is a new instance rather than an old
 * one handed a different src. hls.js holds the previous stream's buffer otherwise, and
 * the first seconds of the new source play as a stall.
 */
const playerKey = ref(0);

const select = (slug) => {
  if (slug === props.selected?.slug) {
    return;
  }

  router.get(
    route('manage.sources.preview'),
    { source: slug },
    {
      preserveScroll: true,
      preserveState: true,
      replace: true,
      onSuccess: () => (playerKey.value += 1),
    },
  );
};

const reload = () => {
  playerKey.value += 1;
  router.reload({ only: ['probe'] });
};

const probeTone = computed(() => {
  if (!props.probe || props.probe.ok === null) {
    return 'border-hairline bg-surface-2 text-fg-2';
  }

  return props.probe.ok
    ? 'border-state-ok/40 bg-state-ok/10 text-state-ok'
    : 'border-state-danger/40 bg-state-danger/10 text-state-danger';
});

const dotClass = (source) => resolve(toneDot, source.status?.tone);

const buttonClass = (source) =>
  source.slug === props.selected?.slug
    ? 'border-state-live/40 bg-state-live/10 text-fg-1'
    : 'border-hairline text-fg-2 hover:bg-surface-3';

const bitrate = (bandwidth) =>
  bandwidth ? `${(Number(bandwidth) / 1_000_000).toFixed(1)} Mbps` : '—';
</script>

<template>
  <ManageLayout>
    <Head title="Source preview" />

    <PageHeader
      title="Source preview"
      subtitle="Watch what an encoder is pushing, before a show exists and without counting as a viewer."
    >
      <template #actions>
        <button
          type="button"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
          @click="reload"
        >
          <ManageIcon name="refresh-cw" />
          Reload
        </button>
        <Link
          v-if="selected"
          :href="selected.edit_url"
          class="inline-flex h-7 items-center gap-1.5 rounded border border-hairline px-2 text-[12px] text-fg-2 transition-colors hover:bg-surface-3"
        >
          <ManageIcon name="pencil" />
          Edit source
        </Link>
      </template>
    </PageHeader>

    <div v-if="!sources.length" class="p-4">
      <p class="rounded border border-hairline bg-surface-2 px-3 py-8 text-center text-[13px] text-fg-3">
        There are no sources yet.
        <Link :href="route('manage.sources.create')" class="text-fg-1 underline">Create one</Link>
        and point an encoder at it.
      </p>
    </div>

    <div v-else class="flex min-h-0 flex-1 flex-col gap-4 p-4 xl:flex-row">
      <div class="flex min-w-0 flex-1 flex-col gap-3">
        <nav class="flex flex-wrap gap-1.5" aria-label="Source">
          <button
            v-for="source in sources"
            :key="source.id"
            type="button"
            class="inline-flex h-7 items-center gap-1.5 rounded border px-2 text-[12px] transition-colors"
            :class="buttonClass(source)"
            :aria-pressed="source.slug === selected?.slug"
            :title="`${source.slug} · ${source.status?.label ?? 'unknown'}`"
            @click="select(source.slug)"
          >
            <!-- The dot is the source's own status, which is the encoder's connection,
                 not whether a show is on it. -->
            <span class="size-1.5 shrink-0 rounded-full" :class="dotClass(source)" />
            {{ source.name }}
          </button>
        </nav>

        <div class="flex flex-wrap items-center gap-2">
          <StatusBadge :status="selected?.status" />
          <span class="font-mono text-[12px] text-fg-3">{{ selected?.slug }}</span>

          <Link
            v-if="selected?.live_show"
            :href="selected.live_show.url"
            class="text-[12px] text-fg-2 underline"
          >
            {{ selected.live_show.title }}
          </Link>
          <span v-else class="text-[12px] text-fg-3">No live show</span>
        </div>

        <div class="overflow-hidden rounded border border-hairline bg-black">
          <div class="aspect-video w-full">
            <StreamPlayer
              v-if="selected"
              :key="`${selected.slug}-${playerKey}`"
              :hls-url="selected.hls_url"
              :show-info="{ title: selected.name }"
            />
          </div>
        </div>

        <p class="text-[11px] text-fg-3">
          The status above is what the app believes; the picture is what the edge is
          handing out. A source can be marked offline and still be arriving.
        </p>
      </div>

      <div class="flex w-full flex-col gap-3 xl:w-96">
        <Deferred data="probe">
          <template #fallback>
            <section class="rounded border border-hairline bg-surface-2 p-3 text-[12px] text-fg-3">
              Asking the edge…
            </section>
          </template>

          <section v-if="probe" class="flex flex-col gap-3">
            <div class="rounded border px-3 py-2 text-[12px]" :class="probeTone">
              <div class="flex items-center gap-1.5 font-medium">
                <ManageIcon :name="probe.ok === null ? 'circle-help' : probe.ok ? 'circle-check' : 'circle-x'" />
                {{ probe.message }}
              </div>
              <p class="mt-1 text-fg-3">
                {{ probe.edge ? `via ${probe.edge}` : 'no edge' }} · checked {{ probe.checked_at }}
              </p>
            </div>

            <div v-if="probe.renditions.length" class="rounded border border-hairline bg-surface-2 p-3">
              <h2 class="mb-2 text-[13px] font-semibold text-fg-1">Ladder on the edge</h2>

              <div class="overflow-x-auto">
                <table class="w-full text-left text-[12px]">
                  <thead class="text-fg-3">
                    <tr>
                      <th class="pb-1 pr-2 font-normal">Rendition</th>
                      <th class="pb-1 pr-2 font-normal">Bitrate</th>
                      <th class="pb-1 pr-2 font-normal">Window</th>
                      <th class="pb-1 font-normal">Newest</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="rendition in probe.renditions" :key="rendition.name" class="border-t border-hairline">
                      <td class="py-1 pr-2 text-fg-1">
                        {{ rendition.name }}
                        <span class="text-fg-3">{{ rendition.resolution ?? '' }}</span>
                      </td>
                      <td class="py-1 pr-2 font-mono text-fg-2">{{ bitrate(rendition.bandwidth) }}</td>
                      <td class="py-1 pr-2 font-mono text-fg-2">
                        {{ rendition.segments }} seg{{ rendition.window_seconds ? ` · ${Math.round(rendition.window_seconds / 60)}m` : '' }}
                      </td>
                      <td class="py-1 font-mono" :class="rendition.available ? 'text-fg-2' : 'text-state-danger'">
                        {{ rendition.age }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        </Deferred>

        <section v-if="selected" class="rounded border border-hairline bg-surface-2 p-3">
          <h2 class="mb-2 text-[13px] font-semibold text-fg-1">Endpoints</h2>

          <dl class="flex flex-col gap-2 text-[12px]">
            <div>
              <dt class="mb-1 text-fg-3">RTMP ingest</dt>
              <dd><CopyableText :value="selected.rtmp_url" /></dd>
            </div>
            <div>
              <dt class="mb-1 text-fg-3">Playlist this page plays</dt>
              <dd><CopyableText :value="selected.hls_url" /></dd>
            </div>
          </dl>
        </section>
      </div>
    </div>
  </ManageLayout>
</template>
