<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';

const props = defineProps({
  /** null when creating */
  server: { type: Object, default: null },
  options: { type: Object, required: true },
  defaults: { type: Object, default: () => ({}) },
  actions: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.server));

const form = useForm({ ...(props.server ?? props.defaults) });

// Type is fixed after creation, so the edge-only controls follow the record on edit and
// the live selection while creating.
const isEdge = computed(() => form.type === 'edge');

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.servers.update', props.server.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.servers.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? `Server ${server.hostname}` : 'New server'" />

    <PageHeader
      :title="isEdit ? server.hostname : 'New manual server'"
      :subtitle="isEdit
        ? `${server.type} · ${server.is_cloud ? 'Hetzner Cloud' : 'manually managed'}`
        : 'Register a server this installation manages by hand'"
    >
      <template #actions>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="flex flex-col gap-4 p-4">
        <FormSection title="Identity" description="Where this server lives and how the app reaches it.">
          <FormField
            v-model="form.hostname"
            label="Hostname"
            required
            mono
            :error="form.errors.hostname"
            helper="e.g. edge-1.example.com, or a container name locally"
          />
          <FormField
            v-model="form.ip"
            label="IP address"
            mono
            :error="form.errors.ip"
            helper="For local Docker: the container IP, or localhost"
          />
          <FormField
            v-model="form.port"
            label="Port"
            type="number"
            :min="1"
            :max="65535"
            required
            :error="form.errors.port"
            helper="80 and 443 are omitted from generated URLs"
          />
          <FormField
            v-if="!isEdit"
            v-model="form.hetzner_id"
            label="Hetzner ID"
            mono
            :error="form.errors.hetzner_id"
            helper="Leave empty for a manually managed server. Cloud servers fill this in themselves."
          />
          <FormField
            v-else
            label="Hetzner ID"
            :model-value="server.hetzner_id"
            readonly
            mono
            helper="Fixed after creation"
          />
        </FormSection>

        <FormSection title="Role and capacity">
          <FormField
            v-if="!isEdit"
            v-model="form.type"
            label="Type"
            type="select"
            :options="options.types"
            required
            :error="form.errors.type"
            helper="Origin ingests and transcodes; edge caches and distributes"
          />
          <FormField v-else label="Type" :model-value="server.type" readonly helper="Fixed after creation" />

          <FormField
            v-model="form.status"
            label="Status"
            type="select"
            :options="options.statuses"
            required
            :error="form.errors.status"
            helper="Manual override; provisioning jobs also write this"
          />

          <FormField
            v-if="isEdge"
            v-model="form.max_clients"
            label="Max clients"
            type="number"
            :min="0"
            :max="99999"
            :error="form.errors.max_clients"
            helper="Viewer slots this edge advertises"
          />

        </FormSection>

        <FormSection title="Authentication" :columns="1">
          <FormField
            v-if="!isEdit"
            v-model="form.shared_secret"
            label="Shared secret"
            mono
            required
            :error="form.errors.shared_secret"
            helper="Used for server-to-server authentication. Generated for you; it cannot be changed later."
          />
          <FormField
            v-else
            label="Shared secret"
            :model-value="server.shared_secret"
            readonly
            mono
            helper="Fixed after creation. Regenerate it from the install script page if it is missing."
          />
        </FormSection>

        <FormSection v-if="isEdit" title="State" :columns="3">
          <FormField label="Viewers" :model-value="server.viewer_count" readonly />
          <FormField label="Health" :model-value="server.health_status ?? 'unknown'" readonly :helper="server.health_check_message" />
          <FormField label="Created" :model-value="server.created_at" readonly />
          <FormField label="Last modified" :model-value="server.updated_at" readonly />
        </FormSection>

        <!-- What the unregistered Filament relation manager was reaching for. -->
        <FormSection v-if="isEdit" title="Assigned users" :columns="1">
          <div v-if="users.length" class="overflow-x-auto rounded border border-hairline">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b border-hairline bg-surface-2 text-[11px] uppercase tracking-wide text-fg-2">
                  <th class="h-7 px-3 text-left">Name</th>
                  <th class="h-7 px-3 text-left">Subject</th>
                  <th class="h-7 px-3 text-right">Reg ID</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="user in users" :key="user.id" class="border-b border-hairline/60 last:border-b-0">
                  <td class="h-8 px-3">{{ user.name }}</td>
                  <td class="h-8 px-3 font-mono text-[12px] text-fg-2">{{ user.sub }}</td>
                  <td class="h-8 px-3 text-right tabular-nums">{{ user.reg_id ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-[13px] text-fg-3">No viewers are assigned to this server.</p>
        </FormSection>
      </div>

      <FormActions
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-label="isEdit ? 'Save changes' : 'Create server'"
      />
    </form>
  </ManageLayout>
</template>
