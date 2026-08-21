<script setup>
/**
 * The chats the bot talks to.
 *
 * The panel at the top is the part somebody needs while standing in a group chat: the
 * bot's name, whether Telegram is actually delivering to us, and a code to paste. The
 * table under it is the configuration, which is a slower kind of decision.
 */
import { Head, Link } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import CopyableText from '@/Components/Manage/CopyableText.vue';
import DataTable from '@/Components/Manage/DataTable.vue';
import FilterBar from '@/Components/Manage/FilterBar.vue';
import ManageIcon from '@/Components/Manage/ManageIcon.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import Pagination from '@/Components/Manage/Pagination.vue';

defineProps({
  table: { type: Object, required: true },
  bot: { type: Object, required: true },
  codes: { type: Array, default: () => [] },
});
</script>

<template>
  <ManageLayout>
    <Head title="Telegram" />

    <PageHeader
      title="Telegram"
      subtitle="One bot, and the chats it posts into. A chat with buttons can start and end its shows."
      :actions="table.pageActions"
    />

    <div class="flex flex-col gap-4 p-4">
      <section
        v-if="!bot.configured"
        class="flex items-start gap-3 rounded border border-hairline bg-surface-2 p-4"
      >
        <ManageIcon name="triangle-alert" class="mt-0.5 text-state-warn" />
        <div class="flex flex-col gap-1">
          <p class="text-[13px] font-semibold text-fg-1">No bot token saved</p>
          <p class="text-[12px] text-fg-2">
            Create a bot with @BotFather in Telegram, then paste its token into
            <Link :href="bot.settings_url" class="text-state-live hover:underline">Settings &gt; Telegram</Link>.
            Nothing is posted and no chat can be linked until then.
          </p>
        </div>
      </section>

      <section v-else class="flex flex-col gap-3 rounded border border-hairline bg-surface-2 p-4">
        <div class="flex flex-wrap items-center gap-3">
          <ManageIcon name="send" class="text-state-live" />
          <span class="text-[13px] font-semibold text-fg-1">{{ bot.username ?? bot.name ?? 'Bot' }}</span>

          <span
            v-if="bot.webhook_registered"
            class="rounded border border-hairline px-1.5 py-0.5 text-[11px] text-state-ok"
          >Webhook live</span>
          <span
            v-else
            class="rounded border border-hairline px-1.5 py-0.5 text-[11px] text-state-danger"
          >Webhook not registered</span>

          <span v-if="bot.pending" class="text-[11px] text-fg-3">{{ bot.pending }} update(s) waiting</span>
        </div>

        <p v-if="bot.webhook_error" class="text-[12px] text-state-danger">
          Telegram's last delivery failed: {{ bot.webhook_error }}
        </p>

        <p class="text-[12px] text-fg-3">
          Delivering to <span class="font-mono">{{ bot.webhook_url }}</span>. Saving the token again
          re-registers it.
        </p>

        <div v-if="codes.length" class="flex flex-col gap-2 border-t border-hairline pt-3">
          <p class="text-[12px] text-fg-2">
            Paste this into the group, as a person who is in it. The bot answers, and the chat
            turns up below with nothing switched on.
          </p>
          <div v-for="code in codes" :key="code.code" class="flex flex-wrap items-center gap-2">
            <CopyableText :value="code.command" />
            <span class="text-[11px] text-fg-3">expires {{ code.expires }}</span>
          </div>
        </div>

        <p v-else class="text-[12px] text-fg-3">
          No link code is open. Use "New link code" above, then send it in the chat within half an hour.
        </p>
      </section>
    </div>

    <FilterBar :table="table" />

    <DataTable :table="table" />

    <Pagination :meta="table.meta" />
  </ManageLayout>
</template>
