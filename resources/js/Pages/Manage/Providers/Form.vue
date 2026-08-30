<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ManageLayout from '@/Layouts/ManageLayout.vue';
import ActionButton from '@/Components/Manage/ActionButton.vue';
import FormActions from '@/Components/Manage/FormActions.vue';
import FormField from '@/Components/Manage/FormField.vue';
import FormSection from '@/Components/Manage/FormSection.vue';
import PageHeader from '@/Components/Manage/PageHeader.vue';
import SettingsNav from '@/Components/Manage/SettingsNav.vue';

const props = defineProps({
  /** null when creating */
  provider: { type: Object, default: null },
  defaults: { type: Object, default: () => ({}) },
  options: { type: Object, default: () => ({ drivers: {}, matches: {}, roles: [] }) },
  actions: { type: Array, default: () => [] },
  navigation: { type: Array, default: () => [] },
  testUrl: { type: String, default: null },
  /** The last test this session ran, shown once and stored nowhere. */
  test: { type: Object, default: null },
});

const isEdit = computed(() => Boolean(props.provider));

const form = useForm({
  driver: 'oidc',
  key: '',
  label: '',
  client_id: '',
  client_secret: '',
  issuer_url: '',
  endpoints: { authorization_endpoint: '', token_endpoint: '', userinfo_endpoint: '' },
  scopes: [],
  packages_url: '',
  enabled: false,
  order: 0,
  grants_baseline: true,
  role_map: [],
  ...(props.provider ?? props.defaults),
  ...(props.provider
    ? {
        endpoints: {
          authorization_endpoint: '',
          token_endpoint: '',
          userinfo_endpoint: '',
          ...(props.provider.endpoints ?? {}),
        },
      }
    : {}),
});

const driverOptions = computed(() =>
  Object.entries(props.options.drivers ?? {}).map(([value, label]) => ({ value, label })),
);

const matchOptions = computed(() =>
  Object.entries(props.options.matches ?? {}).map(([value, label]) => ({ value, label })),
);

const roleOptions = computed(() =>
  (props.options.roles ?? []).map((role) => ({ value: role.id, label: role.name })),
);

const isOidc = computed(() => form.driver === 'oidc');

const scopeList = computed({
  get: () => (form.scopes ?? []).join(' '),
  set: (value) => {
    form.scopes = value.split(/[\s,]+/).filter(Boolean);
  },
});

const addRule = () => {
  form.role_map = [
    ...(form.role_map ?? []),
    { claim: 'groups', match: 'exact', value: '', role_id: roleOptions.value[0]?.value ?? null },
  ];
};

const removeRule = (index) => {
  form.role_map = form.role_map.filter((_, i) => i !== index);
};

const submit = () => {
  if (isEdit.value) {
    form.put(route('manage.providers.update', props.provider.id), { preserveScroll: true });

    return;
  }

  form.post(route('manage.providers.store'));
};
</script>

<template>
  <ManageLayout>
    <Head :title="isEdit ? provider.label : 'New provider'" />

    <PageHeader
      :title="isEdit ? provider.label : 'New provider'"
      :subtitle="isEdit ? `${provider.identities_count} account(s) sign in through it` : null"
    >
      <template #actions>
        <a
          v-if="testUrl"
          :href="testUrl"
          class="flex h-8 items-center rounded border border-hairline px-3 text-[12px] font-medium text-fg-1 hover:bg-surface-2"
        >
          Test
        </a>
        <ActionButton v-for="action in actions" :key="action.name" :action="action" />
      </template>
    </PageHeader>

    <div class="flex min-h-0 flex-1 flex-col items-stretch lg:flex-row">
      <SettingsNav :navigation="navigation" active="identity" />

      <form class="flex min-w-0 flex-1 flex-col" @submit.prevent="submit">
        <div class="flex flex-1 flex-col gap-4 p-4">
          <section
            v-if="test"
            class="rounded border p-3"
            :class="test.ok ? 'border-state-live/40 bg-state-live/5' : 'border-state-danger/40 bg-state-danger/5'"
          >
            <h2 class="text-[13px] font-medium" :class="test.ok ? 'text-state-live' : 'text-state-danger'">
              {{ test.ok ? 'Test passed' : 'Test failed' }}
            </h2>

            <p v-if="!test.ok" class="mt-1 text-[13px] text-fg-1">{{ test.reason }}</p>

            <dl v-if="test.ok" class="mt-2 grid grid-cols-[8rem_minmax(0,1fr)] gap-x-4 gap-y-1 text-[13px]">
              <dt class="text-fg-2">Subject</dt>
              <dd class="font-mono break-all text-fg-1">{{ test.subject }}</dd>
              <dt class="text-fg-2">Name</dt>
              <dd class="text-fg-1">{{ test.name || '-' }}</dd>
              <dt class="text-fg-2">Email</dt>
              <dd class="text-fg-1">{{ test.email || 'Not released' }}</dd>
              <template v-for="claim in test.claims" :key="claim.name">
                <dt class="font-mono text-fg-2">{{ claim.name }}</dt>
                <dd class="font-mono break-all text-fg-1">{{ claim.value || '-' }}</dd>
              </template>
              <dt class="text-fg-2">Would grant</dt>
              <dd class="text-fg-1">{{ test.roles.length ? test.roles.join(', ') : 'No roles' }}</dd>
            </dl>

            <p v-for="note in test.notes ?? []" :key="note" class="mt-2 text-[13px] text-state-warn">
              {{ note }}
            </p>
          </section>

          <FormSection title="Provider">
            <FormField
              v-model="form.driver"
              label="Driver"
              type="select"
              :options="driverOptions"
              :error="form.errors.driver"
              narrow
            />
            <FormField v-model="form.label" label="Name" required :error="form.errors.label" />
            <FormField
              v-model="form.key"
              label="Key"
              mono
              required
              narrow
              :error="form.errors.key"
              helper="The URL segment its redirect and callback live under. Changing it changes the callback URL."
            />
            <FormField
              v-model="form.enabled"
              label="Enabled"
              type="checkbox"
              :error="form.errors.enabled"
            />
            <FormField
              v-model="form.order"
              label="Order"
              type="number"
              narrow
              :error="form.errors.order"
              helper="Lower comes first on the sign-in screen."
            />
          </FormSection>

          <FormSection title="Credentials">
            <FormField
              v-if="isEdit"
              label="Callback URL"
              readonly
              mono
              :model-value="provider.callback_url"
              helper="Register this at the provider."
            />
            <FormField v-model="form.client_id" label="Client ID" :error="form.errors.client_id" />
            <FormField
              v-model="form.client_secret"
              label="Client secret"
              type="password"
              :error="form.errors.client_secret"
            />
            <FormField
              v-if="isOidc"
              v-model="form.issuer_url"
              label="Provider URL"
              type="url"
              :error="form.errors.issuer_url"
              helper="Discovery is read from /.well-known/openid-configuration under it."
            />
            <FormField
              v-model="scopeList"
              label="Scopes"
              mono
              :error="form.errors.scopes"
              helper="Space separated. Empty leaves the driver's own."
            />
          </FormSection>

          <FormSection v-if="isOidc" title="Endpoints">
            <FormField
              v-model="form.endpoints.authorization_endpoint"
              label="Authorization"
              mono
              :error="form.errors['endpoints.authorization_endpoint']"
              helper="Only for a provider whose discovery document is wrong or absent."
            />
            <FormField
              v-model="form.endpoints.token_endpoint"
              label="Token"
              mono
              :error="form.errors['endpoints.token_endpoint']"
            />
            <FormField
              v-model="form.endpoints.userinfo_endpoint"
              label="Userinfo"
              mono
              :error="form.errors['endpoints.userinfo_endpoint']"
            />
            <FormField
              v-model="form.packages_url"
              label="Registration API"
              type="url"
              :error="form.errors.packages_url"
              helper="Attendee packages are read from it per sign-in. Empty skips it."
            />
          </FormSection>

          <FormSection title="Roles">
            <FormField
              v-model="form.grants_baseline"
              label="Grant the baseline role"
              type="checkbox"
              :error="form.errors.grants_baseline"
            />

            <div class="flex flex-col gap-2">
              <div
                v-for="(rule, index) in form.role_map"
                :key="index"
                class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]"
              >
                <input
                  v-model="rule.claim"
                  class="h-8 rounded border border-hairline bg-surface-2 px-2 font-mono text-[13px] text-fg-1"
                  placeholder="claim"
                />
                <select
                  v-model="rule.match"
                  class="h-8 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1"
                >
                  <option v-for="option in matchOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <input
                  v-model="rule.value"
                  class="h-8 rounded border border-hairline bg-surface-2 px-2 font-mono text-[13px] text-fg-1"
                  placeholder="value"
                />
                <select
                  v-model="rule.role_id"
                  class="h-8 rounded border border-hairline bg-surface-2 px-2 text-[13px] text-fg-1"
                >
                  <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <button
                  type="button"
                  class="h-8 rounded border border-hairline px-2 text-[12px] text-fg-2 hover:text-fg-1"
                  @click="removeRule(index)"
                >
                  Remove
                </button>
              </div>

              <button
                type="button"
                class="h-8 self-start rounded border border-hairline px-3 text-[12px] text-fg-2 hover:text-fg-1"
                @click="addRule"
              >
                Add rule
              </button>
            </div>
          </FormSection>
        </div>

        <FormActions
          :processing="form.processing"
          :dirty="form.isDirty"
          :submit-label="isEdit ? 'Save changes' : 'Add provider'"
        />
      </form>
    </div>
  </ManageLayout>
</template>
