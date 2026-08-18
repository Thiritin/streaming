<?php

namespace Tests\Feature\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\DeleteServerJob;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Services\Hetzner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Parity contract for the Servers module.
 *
 * The column and filter expectations are transcribed from
 * docs/admin/current-filament-features.md 2.3, so dropping one fails here rather than
 * being noticed after the cutover.
 */
class ServersTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    private function toast(): array
    {
        return session(SessionKey::FlashData->value)['toast'] ?? [];
    }

    // ---------------------------------------------------------------- access

    public function test_guests_do_not_see_the_panel_at_all(): void
    {
        $this->get(route('manage.servers.index'))->assertNotFound();
    }

    public function test_a_user_without_the_gate_is_forbidden(): void
    {
        $this->actingAs($this->viewer)->get(route('manage.servers.index'))->assertForbidden();
    }

    public function test_staff_can_open_the_list(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->component('Manage/Servers/Index'));
    }

    // ---------------------------------------------------------------- list contract

    public function test_the_list_declares_every_column_the_filament_table_had(): void
    {
        Server::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.columns',
                fn ($columns) => collect($columns)->pluck('key')->all() === [
                    'hetzner_id',
                    'type',
                    // Not a Filament column: the Hetzner instance size, which is now
                    // chosen per server when provisioning rather than hardcoded.
                    'server_type',
                    'hostname',
                    'ip',
                    'port',
                    'status',
                    'viewer_count',
                    'heartbeat',
                    'health_status',
                    'max_clients',
                ],
            ));
    }

    public function test_the_list_declares_the_status_and_type_filters(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.filters.0.key', 'status')
                ->where('table.filters.0.multiple', true)
                ->where('table.filters.1.key', 'type')
                ->where('table.filters.1.multiple', false)
            );
    }

    public function test_deleted_servers_are_hidden_until_the_status_filter_asks_for_them(): void
    {
        Server::factory()->create(['hostname' => 'edge-live.test']);
        Server::factory()->status(ServerStatusEnum::DELETED)->create(['hostname' => 'edge-gone.test']);

        $hostnames = fn (iterable $rows) => collect($rows)->pluck('cells.hostname')->all();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => $hostnames($rows) === ['edge-live.test'],
            ));

        // Unlike Filament, where the exclusion was a hard query scope and this choice could
        // only ever return an empty table.
        $this->actingAs($this->admin)
            ->get(route('manage.servers.index', ['filter' => ['status' => ['deleted']]]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => $hostnames($rows) === ['edge-gone.test'],
            ));
    }

    public function test_the_type_filter_narrows_the_list(): void
    {
        Server::factory()->create(['hostname' => 'edge-1.test']);
        Server::factory()->origin()->create(['hostname' => 'origin-1.test']);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index', ['filter' => ['type' => 'origin']]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.hostname')->all() === ['origin-1.test'],
            ));
    }

    public function test_search_matches_hostname_and_hetzner_id(): void
    {
        Server::factory()->create(['hostname' => 'edge-berlin.test']);
        Server::factory()->cloud()->create(['hostname' => 'edge-falkenstein.test', 'hetzner_id' => '4242424']);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index', ['search' => 'berlin']))
            ->assertInertia(fn (Assert $page) => $page->count('table.rows', 1));

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index', ['search' => '4242424']))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.hostname')->all() === ['edge-falkenstein.test'],
            ));
    }

    public function test_an_edge_row_reports_capacity_while_an_origin_row_reports_none(): void
    {
        Server::factory()->healthy()->create(['hostname' => 'edge-1.test', 'viewer_count' => 25, 'max_clients' => 100]);
        Server::factory()->origin()->healthy()->create(['hostname' => 'origin-1.test', 'viewer_count' => 7]);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $rows = collect($page->toArray()['props']['table']['rows'])->keyBy('cells.hostname');

                $this->assertSame('25% capacity', $rows['edge-1.test']['cells']['viewer_count']['description']);
                $this->assertSame('Healthy', $rows['edge-1.test']['cells']['health_status']['label'] ?? null);

                // Origin servers have no viewer slots and no health endpoint to check.
                $this->assertNull($rows['origin-1.test']['cells']['viewer_count']);
                $this->assertNull($rows['origin-1.test']['cells']['health_status']);
            });
    }

    public function test_a_stale_heartbeat_is_reported_as_a_danger_icon(): void
    {
        Server::factory()->create(['hostname' => 'stale.test']);
        Server::factory()->withHeartbeat()->create(['hostname' => 'fresh.test']);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $rows = collect($page->toArray()['props']['table']['rows'])->keyBy('cells.hostname');

                $this->assertSame('danger', $rows['stale.test']['cells']['heartbeat']['tone']);
                $this->assertSame('No heartbeat received', $rows['stale.test']['cells']['heartbeat']['title']);
                $this->assertSame('ok', $rows['fresh.test']['cells']['heartbeat']['tone']);
            });
    }

    // ---------------------------------------------------------------- row actions

    public function test_a_cloud_server_is_offered_deprovision_and_never_delete(): void
    {
        Server::factory()->cloud()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $actions = collect($page->toArray()['props']['table']['rows'][0]['actions'])->pluck('name');

                $this->assertContains('deprovision', $actions);
                $this->assertNotContains('delete', $actions);
            });
    }

    public function test_a_manual_server_is_offered_delete_and_never_deprovision(): void
    {
        Server::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $actions = collect($page->toArray()['props']['table']['rows'][0]['actions'])->pluck('name');

                $this->assertContains('delete', $actions);
                $this->assertNotContains('deprovision', $actions);
            });
    }

    public function test_a_moderator_may_read_the_list_but_is_offered_no_mutations(): void
    {
        Server::factory()->cloud()->create();

        $this->actingAs($this->moderator)
            ->get(route('manage.servers.index'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $table = $page->toArray()['props']['table'];

                $this->assertSame([], collect($table['pageActions'])->pluck('name')->all());
                $this->assertSame(['edit'], collect($table['rows'][0]['actions'])->pluck('name')->all());
            });
    }

    // ---------------------------------------------------------------- create and update

    public function test_creating_a_manual_edge_server(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.servers.store'), [
                'hostname' => 'edge-new.test',
                'ip' => '10.0.0.5',
                'port' => 8080,
                'type' => ServerTypeEnum::EDGE->value,
                'status' => ServerStatusEnum::ACTIVE->value,
                'shared_secret' => str_repeat('a', 40),
                'max_clients' => 250,
            ])
            ->assertRedirect();

        $server = Server::where('hostname', 'edge-new.test')->sole();

        $this->assertSame(ServerTypeEnum::EDGE, $server->type);
        $this->assertSame(250, $server->max_clients);
        $this->assertNull($server->hetzner_id);
        $this->assertSame('Server created', $this->toast()['title']);
    }

    public function test_creating_an_origin_server_ignores_the_edge_only_fields(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.servers.store'), [
                'hostname' => 'origin-new.test',
                'port' => 443,
                'type' => ServerTypeEnum::ORIGIN->value,
                'status' => ServerStatusEnum::ACTIVE->value,
                'shared_secret' => str_repeat('b', 40),
                'max_clients' => 5,
            ])
            ->assertRedirect();

        $server = Server::where('hostname', 'origin-new.test')->sole();

        // The model default stands in for the value the form never showed.
        $this->assertSame(1000, $server->max_clients);
    }

    public function test_the_create_form_rejects_an_incomplete_payload(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.servers.store'), ['port' => 70000])
            ->assertSessionHasErrors(['hostname', 'port', 'type', 'status', 'shared_secret']);

        $this->assertSame(0, Server::count());
    }

    public function test_updating_a_server_cannot_change_its_type_secret_or_hetzner_id(): void
    {
        $server = Server::factory()->cloud()->create([
            'type' => ServerTypeEnum::EDGE,
            'shared_secret' => str_repeat('c', 40),
            'hetzner_id' => '111111',
        ]);

        $this->actingAs($this->admin)
            ->from(route('manage.servers.edit', $server))
            ->put(route('manage.servers.update', $server), [
                'hostname' => 'renamed.test',
                'ip' => $server->ip,
                'port' => 443,
                'status' => ServerStatusEnum::ERROR->value,
                'max_clients' => 10,
                // All three should be ignored rather than applied.
                'type' => ServerTypeEnum::ORIGIN->value,
                'shared_secret' => 'hijacked',
                'hetzner_id' => '999999',
            ])
            ->assertRedirect(route('manage.servers.edit', $server));

        $server->refresh();

        $this->assertSame('renamed.test', $server->hostname);
        $this->assertSame(ServerStatusEnum::ERROR, $server->status);
        $this->assertSame(10, $server->max_clients);
        $this->assertSame(ServerTypeEnum::EDGE, $server->type);
        $this->assertSame(str_repeat('c', 40), $server->shared_secret);
        $this->assertSame('111111', $server->hetzner_id);
    }

    public function test_a_moderator_cannot_create_or_update_a_server(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->moderator)
            ->post(route('manage.servers.store'), [
                'hostname' => 'nope.test',
                'port' => 8080,
                'type' => ServerTypeEnum::EDGE->value,
                'status' => ServerStatusEnum::ACTIVE->value,
                'shared_secret' => str_repeat('d', 40),
            ])
            ->assertForbidden();

        $this->actingAs($this->moderator)
            ->put(route('manage.servers.update', $server), [
                'hostname' => 'nope.test',
                'port' => 8080,
                'status' => ServerStatusEnum::ACTIVE->value,
            ])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- delete and deprovision

    public function test_deleting_a_manual_server_unassigns_its_viewers(): void
    {
        $server = Server::factory()->create();
        $user = User::factory()->create(['server_id' => $server->id]);

        $this->actingAs($this->admin)
            ->delete(route('manage.servers.destroy', $server))
            ->assertRedirect(route('manage.servers.index'));

        $this->assertSame(0, Server::whereKey($server->id)->count());
        $this->assertNull($user->fresh()->server_id);
        $this->assertSame('Server deleted', $this->toast()['title']);
    }

    public function test_a_cloud_server_cannot_be_deleted_outright(): void
    {
        $server = Server::factory()->cloud()->create();

        $this->actingAs($this->admin)
            ->delete(route('manage.servers.destroy', $server))
            ->assertForbidden();

        $this->assertSame(1, Server::whereKey($server->id)->count());
    }

    public function test_deprovisioning_dispatches_the_teardown_job(): void
    {
        Bus::fake();

        $server = Server::factory()->cloud()->create();

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.deprovision', $server))
            ->assertRedirect(route('manage.servers.index'));

        Bus::assertDispatched(DeleteServerJob::class);
        $this->assertSame('Deprovisioning started', $this->toast()['title']);
    }

    public function test_a_manual_server_cannot_be_deprovisioned(): void
    {
        Bus::fake();

        $server = Server::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('manage.servers.deprovision', $server))
            ->assertForbidden();

        Bus::assertNotDispatched(DeleteServerJob::class);
    }

    // ---------------------------------------------------------------- provisioning

    public function test_provisioning_an_edge_server_creates_a_pending_row_and_dispatches_the_job(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::EDGE->value])
            ->assertRedirect(route('manage.servers.index'));

        $server = Server::sole();

        $this->assertSame(ServerTypeEnum::EDGE, $server->type);
        $this->assertSame(ServerStatusEnum::PROVISIONING, $server->status);
        $this->assertSame('pending', $server->hostname);
        $this->assertSame(443, $server->port);
        $this->assertSame(100, $server->max_clients);
        $this->assertNotEmpty($server->shared_secret);

        Bus::assertDispatched(CreateVirtualMachineJob::class);
        $this->assertSame('Server Provisioning Started', $this->toast()['title']);
    }

    public function test_provisioning_an_origin_server_uses_the_larger_client_ceiling(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::ORIGIN->value]);

        $this->assertSame(1000, Server::sole()->max_clients);
    }

    // ------------------------------------------------------------- instance size

    public function test_the_chosen_instance_size_is_recorded_and_used(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.servers.provision'), [
                'type' => ServerTypeEnum::ORIGIN->value,
                'server_type' => 'ccx23',
            ]);

        $server = Server::sole();

        $this->assertSame('ccx23', $server->server_type);
        // What CreateVirtualMachineJob actually sends to Hetzner.
        $this->assertSame('ccx23', $server->hetznerServerType());
    }

    public function test_omitting_the_size_falls_back_to_the_configured_default(): void
    {
        Bus::fake();
        config(['stream.server.defaults.origin' => 'ccx33']);

        $this->actingAs($this->admin)
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::ORIGIN->value]);

        $this->assertSame('ccx33', Server::sole()->server_type);
    }

    /**
     * The field reaches the Hetzner API, so it is validated against the configured list
     * rather than accepted freely. Without this an operator could name any instance
     * Hetzner sells, including one that bills at several euro an hour.
     */
    public function test_an_unlisted_instance_size_is_rejected(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.servers.provision'), [
                'type' => ServerTypeEnum::EDGE->value,
                'server_type' => 'ccx63-does-not-exist',
            ])
            ->assertSessionHasErrors('server_type');

        $this->assertSame(0, Server::count());
        Bus::assertNotDispatched(CreateVirtualMachineJob::class);
    }

    /**
     * Servers provisioned before the column existed have no recorded size, and must not
     * be guessed at as something they might not be.
     */
    public function test_a_server_without_a_recorded_size_falls_back_by_role(): void
    {
        config([
            'stream.server.defaults.origin' => 'ccx33',
            'stream.server.defaults.edge' => 'cpx21',
        ]);

        $origin = Server::factory()->origin()->create(['server_type' => null]);
        $edge = Server::factory()->create(['server_type' => null]);

        $this->assertSame('ccx33', $origin->hetznerServerType());
        $this->assertSame('cpx21', $edge->hetznerServerType());
    }

    #[DataProvider('blockingOriginStatuses')]
    public function test_a_second_origin_server_is_refused(ServerStatusEnum $status): void
    {
        Bus::fake();

        Server::factory()->origin()->status($status)->create();

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::ORIGIN->value])
            ->assertRedirect(route('manage.servers.index'));

        $this->assertSame(1, Server::count());
        Bus::assertNotDispatched(CreateVirtualMachineJob::class);

        $this->assertSame([
            'tone' => 'danger',
            'title' => 'Cannot Create Origin Server',
            'body' => 'An origin server already exists or is being provisioned. Only one origin server is allowed.',
        ], $this->toast());
    }

    public static function blockingOriginStatuses(): array
    {
        return [
            'active' => [ServerStatusEnum::ACTIVE],
            'provisioning' => [ServerStatusEnum::PROVISIONING],
        ];
    }

    public function test_a_replacement_origin_may_be_provisioned_once_the_old_one_is_gone(): void
    {
        Bus::fake();

        Server::factory()->origin()->status(ServerStatusEnum::DELETED)->create();

        $this->actingAs($this->admin)
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::ORIGIN->value]);

        $this->assertSame(2, Server::count());
        Bus::assertDispatched(CreateVirtualMachineJob::class);
    }

    public function test_provisioning_validates_the_type(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.servers.provision'), ['type' => 'gigantic'])
            ->assertSessionHasErrors('type');

        Bus::assertNotDispatched(CreateVirtualMachineJob::class);
    }

    public function test_a_moderator_cannot_provision(): void
    {
        Bus::fake();

        $this->actingAs($this->moderator)
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::EDGE->value])
            ->assertForbidden();

        Bus::assertNotDispatched(CreateVirtualMachineJob::class);
    }

    // ---------------------------------------------------------------- install script

    public function test_the_install_script_page_builds_a_tab_per_config(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.install-script', $server))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                $keys = collect($props['tabs'])->pluck('key');

                $this->assertContains('install', $keys);
                $this->assertContains('cloud-init', $keys);

                // The FFmpeg placeholder tabs the Filament page shipped are gone, and no tab
                // is ever rendered empty.
                foreach ($props['tabs'] as $tab) {
                    $this->assertNotSame('', trim($tab['content']));
                    $this->assertStringNotContainsString('not available in current implementation', $tab['content']);
                }
            });
    }

    public function test_an_origin_server_gets_the_srs_tab_and_an_edge_server_does_not(): void
    {
        $origin = Server::factory()->origin()->create();
        $edge = Server::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.install-script', $origin))
            ->assertInertia(fn (Assert $page) => $page->where(
                'tabs',
                fn ($tabs) => collect($tabs)->pluck('key')->contains('srs'),
            ));

        $this->actingAs($this->admin)
            ->get(route('manage.servers.install-script', $edge))
            ->assertInertia(fn (Assert $page) => $page->where(
                'tabs',
                fn ($tabs) => ! collect($tabs)->pluck('key')->contains('srs'),
            ));
    }

    public function test_the_install_script_downloads_under_a_predictable_filename(): void
    {
        $server = Server::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('manage.servers.install-script.download', $server));

        $response->assertSuccessful();
        $response->assertDownload("install-{$server->id}.sh");
    }

    public function test_regenerating_backfills_a_missing_shared_secret(): void
    {
        // The column is NOT NULL, so a "missing" secret in practice means an empty string.
        $server = Server::factory()->create();
        $server->forceFill(['shared_secret' => ''])->saveQuietly();

        $this->actingAs($this->admin)
            ->from(route('manage.servers.install-script', $server))
            ->post(route('manage.servers.install-script.regenerate', $server))
            ->assertRedirect(route('manage.servers.install-script', $server));

        $this->assertNotEmpty($server->fresh()->shared_secret);
        $this->assertSame('Scripts Regenerated', $this->toast()['title']);
    }

    public function test_a_moderator_cannot_read_the_install_script(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->moderator)
            ->get(route('manage.servers.install-script', $server))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- detail page

    /**
     * Reading a server and changing one are different pages: almost every visit is to
     * look at a graph, and landing on a form for that was the old behaviour.
     */
    public function test_the_server_page_shows_its_metrics(): void
    {
        $server = Server::factory()->create(['viewer_count' => 12]);

        ServerMetric::create([
            'server_id' => $server->id,
            'recorded_at' => now(),
            'cpu_percent' => 12.5,
            'memory_used_bytes' => 1_073_741_824,
            'memory_total_bytes' => 4_294_967_296,
            'net_tx_bytes_per_sec' => 12_500_000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.show', $server))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Servers/Show')
                ->where('server.hostname', $server->hostname)
                ->where('metrics.range', '6h')
                ->where('metrics.has_samples', true)
                ->where('metrics.cards.1.value', '13%')
            );
    }

    public function test_the_server_page_takes_a_time_range(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.show', ['server' => $server, 'range' => '7d']))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('metrics.range', '7d'));
    }

    public function test_the_edit_page_lists_the_viewers_assigned_to_the_server(): void
    {
        $server = Server::factory()->create();
        User::factory()->create(['server_id' => $server->id, 'name' => 'Assigned Viewer']);
        User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.edit', $server))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Servers/Form')
                ->where('server.is_cloud', false)
                ->where('server.is_edge', true)
                ->count('users', 1)
                ->where('users.0.name', 'Assigned Viewer')
            );
    }

    // ------------------------------------------------- instance size catalogue

    /**
     * The dropdown asks Hetzner what it can actually place, because a fixed list goes
     * stale two different ways and both look identical from the app: a generation is
     * retired (the whole `cpx21`/`cpx31`/`cpx41` line stopped being placeable in every
     * EU datacenter), or a size is temporarily out of stock. Either way provisioning
     * dies with `422 unsupported location for server type` and leaves a row at
     * `pending` with no IP.
     */
    public function test_the_size_catalogue_comes_from_hetzner(): void
    {
        config(['services.hetzner.token' => 'test-token', 'stream.server.location' => 'nbg1']);
        Cache::forget('hetzner.server_types');

        Http::fake([
            'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [
                ['id' => 1, 'name' => 'cx23', 'cores' => 2, 'memory' => 4, 'architecture' => 'x86',
                    'cpu_type' => 'shared', 'prices' => [['price_hourly' => ['gross' => '0.0088']]]],
                ['id' => 2, 'name' => 'ccx33', 'cores' => 8, 'memory' => 32, 'architecture' => 'x86',
                    'cpu_type' => 'dedicated', 'prices' => [['price_hourly' => ['gross' => '0.2259']]]],
                ['id' => 3, 'name' => 'cpx21', 'cores' => 3, 'memory' => 4, 'architecture' => 'x86',
                    'cpu_type' => 'shared', 'prices' => [['price_hourly' => ['gross' => '0.0513']]]],
                ['id' => 4, 'name' => 'cax11', 'cores' => 2, 'memory' => 4, 'architecture' => 'arm',
                    'cpu_type' => 'shared', 'prices' => [['price_hourly' => ['gross' => '0.0096']]]],
            ]]),
            'api.hetzner.cloud/v1/datacenters*' => Http::response(['datacenters' => [
                ['name' => 'nbg1-dc3', 'location' => ['name' => 'nbg1'],
                    'server_types' => ['available' => [1, 2, 4]]],
                ['name' => 'fsn1-dc14', 'location' => ['name' => 'fsn1'],
                    'server_types' => ['available' => [1, 2, 3]]],
            ]]),
        ]);

        $types = Hetzner::availableServerTypes();

        $this->assertArrayHasKey('cx23', $types);
        $this->assertArrayHasKey('ccx33', $types);

        // Placeable in fsn1 but not our location.
        $this->assertArrayNotHasKey('cpx21', $types);

        // ARM is cheap and useless: the transcoder and uploader images are x86 only.
        $this->assertArrayNotHasKey('cax11', $types);

        // Cheapest first, because the list is read by someone deciding what to spend.
        $this->assertSame('cx23', array_key_first($types));
        $this->assertStringContainsString('0.009', $types['cx23']);
    }

    public function test_the_catalogue_falls_back_when_hetzner_is_unreachable(): void
    {
        config(['services.hetzner.token' => 'test-token']);
        Cache::forget('hetzner.server_types');

        Http::fake(['api.hetzner.cloud/*' => Http::response('', 500)]);

        // A stale list beats an empty dropdown when someone is provisioning under
        // pressure.
        $this->assertSame(config('stream.server.types'), Hetzner::availableServerTypes());
    }

    public function test_no_token_means_no_api_call(): void
    {
        config(['services.hetzner.token' => null]);
        Cache::forget('hetzner.server_types');
        Http::fake();

        $this->assertSame(config('stream.server.types'), Hetzner::availableServerTypes());

        Http::assertNothingSent();
    }
}
