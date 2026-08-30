<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Jobs\Server\Deprovision\DeleteDnsRecordJob;
use App\Jobs\Server\Provision\CreateDnsRecordJob;
use App\Models\Server;
use App\Services\Dns\DnsManager;
use App\Services\Dns\DnsProvider;
use App\Services\Dns\DnsRecord;
use App\Services\Dns\Drivers\Rfc2136Driver;
use App\Services\DnsKeyService;
use App\Services\DriverCheck;
use App\Services\ShellCommand;
use App\Support\Manage\Settings;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The DNS drivers, and the two jobs that are the only things which call them.
 *
 * The point of the abstraction is that a record can be written by one provider and
 * removed by another later, so most of what is worth pinning here is about which driver
 * answers rather than about what any one of them says on the wire.
 */
class DnsProviderTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        Config::set('dns.zone', 'stream.example.org');
        Config::set('dns.ttl', 60);
    }

    /**
     * The nsupdate script used to be a heredoc on a shell command line, and a heredoc is
     * expanded by the shell before nsupdate sees a byte of it. The zone, the name server
     * and a manual server's hostname are all settings or form fields now, so `$(...)` in
     * any of them was a command run as the queue user.
     */
    public function test_a_hostile_zone_or_hostname_never_reaches_the_shell(): void
    {
        $driver = new Rfc2136Driver('ns1.example.org', 'stream.example.org', 'k', 'hmac-sha256', 'c2VjcmV0');

        $this->expectException(\InvalidArgumentException::class);

        $driver->upsert(new DnsRecord('$(touch /tmp/pwned).stream.example.org', 'A', '1.2.3.4', 60));
    }

    public function test_a_hostile_name_server_is_refused_before_nsupdate_runs(): void
    {
        $service = new DnsKeyService('k', 'hmac-sha256', 'c2VjcmV0', 'ns1.example.org; touch /tmp/pwned', 'stream.example.org');

        $this->expectException(\InvalidArgumentException::class);

        $service->executeNsupdate('update delete x.stream.example.org A');
    }

    public function test_the_pane_refuses_a_zone_that_is_not_a_hostname(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'manual',
                'dns_driver' => 'none',
                'dns_zone' => 'stream.example.org; touch /tmp/pwned',
            ],
        ])->assertSessionHasErrors('values.dns_zone');
    }

    public function test_cloudflare_creates_a_record_that_does_not_exist(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => []]),
            'api.cloudflare.com/*/dns_records' => Http::response(['result' => ['id' => 'rec-1']]),
        ]);

        $this->driver('cloudflare')->upsert(new DnsRecord('edge-1.stream.example.org', 'A', '1.2.3.4', 60));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/zones/zone-1/dns_records')
                && $request['content'] === '1.2.3.4'
                // Proxied would answer with Cloudflare's address, which intercepts the
                // ACME challenge and hides the edge from the placement model.
                && $request['proxied'] === false;
        });
    }

    public function test_cloudflare_replaces_a_record_that_already_exists(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [['id' => 'rec-1', 'content' => '9.9.9.9']]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $this->driver('cloudflare')->upsert(new DnsRecord('edge-1.stream.example.org', 'A', '1.2.3.4', 60));

        // POST is create-only on Cloudflare, so a retried provision has to PUT.
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/dns_records/rec-1'));

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_cloudflare_deletes_by_the_id_it_looked_up(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [['id' => 'rec-1']]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $this->driver('cloudflare')->delete(new DnsRecord('edge-1.stream.example.org'));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/dns_records/rec-1'));
    }

    public function test_cloudflare_deleting_an_absent_record_is_success(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake(['api.cloudflare.com/*' => Http::response(['result' => []])]);

        $this->driver('cloudflare')->delete(new DnsRecord('gone.stream.example.org'));

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /**
     * A name that is already duplicated is the state the old additive write left behind,
     * and it is exactly what teardown has to be able to clean up. Taking `$records[0]`
     * only would leave the second record resolving to a machine that is gone.
     */
    public function test_cloudflare_removes_every_record_with_that_name(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [
                ['id' => 'rec-1'], ['id' => 'rec-2'],
            ]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $this->driver('cloudflare')->delete(new DnsRecord('edge-1.stream.example.org'));

        foreach (['rec-1', 'rec-2'] as $id) {
            Http::assertSent(fn ($request) => $request->method() === 'DELETE'
                && str_contains($request->url(), '/dns_records/'.$id));
        }
    }

    public function test_cloudflare_upsert_leaves_exactly_one_record(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [
                ['id' => 'rec-1'], ['id' => 'rec-2'],
            ]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $this->driver('cloudflare')->upsert(new DnsRecord('edge-1.stream.example.org', 'A', '1.2.3.4', 60));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/dns_records/rec-1'));

        // The duplicate goes, or the resolver goes on round-robining between a live box
        // and an address somebody else now holds.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/dns_records/rec-2'));
    }

    /**
     * Hetzner DNS has no name filter on the record list and pages at 100, so an unpaged
     * read answers "no such record" for a name that exists once a fleet zone passes that
     * - the upsert then POSTs a duplicate and the delete leaves the record behind, which
     * is the orphan this driver exists to avoid.
     */
    public function test_hetzner_dns_pages_the_record_list(): void
    {
        Config::set('dns.hetzner.token', 'hz-token');
        Config::set('dns.hetzner.zone_id', 'zone-9');

        $first = array_map(
            fn (int $i) => ['id' => "filler-{$i}", 'type' => 'A', 'name' => "filler-{$i}"],
            range(1, 100),
        );

        Http::fake(function ($request) use ($first) {
            if (str_contains($request->url(), '/records')) {
                $page = (int) ($request->data()['page'] ?? 1);

                return Http::response($page === 1
                    ? ['records' => $first, 'meta' => ['pagination' => ['last_page' => 2]]]
                    : ['records' => [['id' => 'r-late', 'type' => 'A', 'name' => 'edge-1']]]);
            }

            return Http::response([]);
        });

        $this->driver('hetzner')->delete(new DnsRecord('edge-1.stream.example.org'));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/records/r-late'));
    }

    /**
     * An API error read back as "the record is absent" logged a refused token in exactly
     * the same words as a propagation delay.
     */
    public function test_cloudflare_resolve_surfaces_an_api_error(): void
    {
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake(['api.cloudflare.com/*' => Http::response([], 403)]);

        $this->expectException(RequestException::class);

        $this->driver('cloudflare')->resolve('edge-1.stream.example.org');
    }

    /**
     * A record with no address is worse than no record: the name exists and answers
     * nothing.
     */
    public function test_no_record_is_written_without_an_address(): void
    {
        Config::set('dns.driver', 'cloudflare');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake();

        $server = Server::factory()->create(['hostname' => 'edge-1.stream.example.org', 'ip' => null]);

        (new CreateDnsRecordJob($server))->handle(app(DnsManager::class));

        Http::assertNothingSent();
        $this->assertNull($server->fresh()->dnsProvider());
    }

    public function test_hetzner_dns_removes_every_record_with_that_name(): void
    {
        Config::set('dns.hetzner.token', 'hz-token');
        Config::set('dns.hetzner.zone_id', 'zone-9');

        Http::fake([
            'dns.hetzner.com/api/v1/records?*' => Http::response(['records' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'edge-1'],
                ['id' => 'r2', 'type' => 'A', 'name' => 'edge-1'],
                ['id' => 'r3', 'type' => 'A', 'name' => 'edge-2'],
            ]]),
            'dns.hetzner.com/*' => Http::response([]),
        ]);

        $this->driver('hetzner')->delete(new DnsRecord('edge-1.stream.example.org'));

        foreach (['r1', 'r2'] as $id) {
            Http::assertSent(fn ($request) => $request->method() === 'DELETE'
                && str_ends_with($request->url(), '/records/'.$id));
        }

        // Another name in the same zone is not ours to remove.
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/records/r3'));
    }

    public function test_hetzner_dns_writes_the_label_relative_to_its_zone(): void
    {
        Config::set('dns.hetzner.token', 'hz-token');
        Config::set('dns.hetzner.zone_id', 'zone-9');

        Http::fake([
            'dns.hetzner.com/api/v1/records?*' => Http::response(['records' => []]),
            'dns.hetzner.com/api/v1/records' => Http::response(['record' => ['id' => 'r1']]),
        ]);

        $this->driver('hetzner')->upsert(new DnsRecord('edge-1.stream.example.org', 'A', '1.2.3.4', 60));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['name'] === 'edge-1'
                && $request['value'] === '1.2.3.4'
                && $request['zone_id'] === 'zone-9';
        });
    }

    public function test_hetzner_dns_replaces_a_record_that_already_exists(): void
    {
        Config::set('dns.hetzner.token', 'hz-token');
        Config::set('dns.hetzner.zone_id', 'zone-9');

        Http::fake([
            'dns.hetzner.com/api/v1/records?*' => Http::response(['records' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'edge-1', 'value' => '9.9.9.9'],
            ]]),
            'dns.hetzner.com/*' => Http::response(['record' => []]),
        ]);

        $this->driver('hetzner')->upsert(new DnsRecord('edge-1.stream.example.org', 'A', '1.2.3.4', 60));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/records/r1'));

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_hetzner_dns_deletes_by_the_id_it_looked_up(): void
    {
        Config::set('dns.hetzner.token', 'hz-token');
        Config::set('dns.hetzner.zone_id', 'zone-9');

        Http::fake([
            'dns.hetzner.com/api/v1/records?*' => Http::response(['records' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'edge-1'],
            ]]),
            'dns.hetzner.com/*' => Http::response([]),
        ]);

        $this->driver('hetzner')->delete(new DnsRecord('edge-1.stream.example.org'));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/records/r1'));
    }

    /**
     * rfc2136 is the driver the original incident happened on, and `update replace` is
     * the whole of what it decides. A bare `update add` is additive, so a second run of
     * the chain left two A records for one hostname and the resolver round-robined
     * between a live box and an address Hetzner had reassigned to somebody else.
     */
    public function test_rfc2136_replaces_rather_than_adds(): void
    {
        $shell = $this->fakeShell();

        $this->driver('rfc2136')->upsert(new DnsRecord('edge-1.stream.example.org', 'A', '1.2.3.4', 60));

        $script = $shell->lastInput();

        $this->assertStringContainsString('update replace edge-1.stream.example.org 60 A 1.2.3.4', $script);
        $this->assertStringNotContainsString('update add', $script);

        // The script goes to stdin, never onto the command line, so no shell ever sees
        // a hostname or a zone.
        $this->assertStringStartsWith('nsupdate -v -k ', $shell->lastCommand());
        $this->assertStringNotContainsString('edge-1', $shell->lastCommand());
    }

    public function test_rfc2136_deletes_the_whole_name(): void
    {
        $shell = $this->fakeShell();

        $this->driver('rfc2136')->delete(new DnsRecord('edge-1.stream.example.org'));

        $this->assertStringContainsString('update delete edge-1.stream.example.org A', $shell->lastInput());
    }

    /**
     * `grant <key> name *.zone A` is the common policy, and it refuses everything that is
     * not an A record - so a probe writing a TXT called a working key broken. A random
     * name, because a fixed one destroys whatever is already there.
     */
    public function test_the_rfc2136_check_probes_with_the_type_it_writes(): void
    {
        $shell = $this->fakeShell(['dig +short SOA' => 'ns1.example.org. hostmaster. 1 2 3 4 5']);

        $result = $this->driver('rfc2136')->check();

        $this->assertTrue($result->ok);

        $script = $shell->lastInput();

        $this->assertMatchesRegularExpression('/update add _check-[a-z0-9]{12}\.stream\.example\.org\. 0 A /', $script);
        $this->assertStringContainsString('update delete _check-', $script);
        $this->assertStringNotContainsString('TXT', $script);
    }

    /**
     * The failure this exists to stop: two chains reach CreateDnsRecordJob for one
     * server, and an additive write left two A records for one hostname - a resolver
     * then answered with the live box half the time and a dead address the other half.
     */
    public function test_a_retried_provision_does_not_stack_a_second_record(): void
    {
        Config::set('dns.driver', 'cloudflare');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        $existing = [];

        Http::fake(function ($request) use (&$existing) {
            if ($request->method() === 'POST') {
                $existing = [['id' => 'rec-1', 'content' => $request['content']]];

                return Http::response(['result' => ['id' => 'rec-1']]);
            }

            if ($request->method() === 'PUT') {
                $existing = [['id' => 'rec-1', 'content' => $request['content']]];

                return Http::response(['result' => ['id' => 'rec-1']]);
            }

            return Http::response(['result' => $existing]);
        });

        $server = Server::factory()->create(['hostname' => 'edge-1.stream.example.org', 'ip' => '1.2.3.4']);

        (new CreateDnsRecordJob($server))->handle(app(DnsManager::class));
        (new CreateDnsRecordJob($server->fresh()))->handle(app(DnsManager::class));

        $creates = 0;

        Http::assertSent(function ($request) use (&$creates) {
            if ($request->method() === 'POST') {
                $creates++;
            }

            return true;
        });

        $this->assertSame(1, $creates, 'The second run must replace the record, not add another.');
        $this->assertCount(1, $existing);
        $this->assertSame('1.2.3.4', $existing[0]['content']);
    }

    public function test_the_create_job_records_which_provider_wrote_the_record(): void
    {
        Config::set('dns.driver', 'none');

        $server = Server::factory()->create(['hostname' => 'edge-1.stream.example.org', 'ip' => '1.2.3.4']);

        (new CreateDnsRecordJob($server))->handle(app(DnsManager::class));

        $this->assertSame('none', $server->fresh()->dnsProvider());
        $this->assertSame('stream.example.org', $server->fresh()->dnsZone());
    }

    /**
     * Switching the installation's driver must not strand the fleet's existing names.
     * The delete resolves the driver the row was written by, never the selected one.
     */
    public function test_the_delete_uses_the_driver_named_on_the_row(): void
    {
        Config::set('dns.driver', 'rfc2136');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [['id' => 'rec-1']]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $server = Server::factory()->create([
            'hostname' => 'edge-1.stream.example.org',
            'metadata' => ['dns_provider' => 'cloudflare', 'dns_zone' => 'stream.example.org'],
            'status' => ServerStatusEnum::DEPROVISIONING,
        ]);

        (new DeleteDnsRecordJob($server))->handle(app(DnsManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/dns_records/rec-1'));
    }

    /**
     * A DNS failure must not keep the machine alive. The job runs before
     * DeleteVirtualMachineJob in the chain, and Bus::chain stops at the first failure,
     * so one broken key used to leave every teardown's VM running and billing.
     */
    public function test_a_dns_failure_does_not_abort_the_teardown(): void
    {
        Config::set('dns.driver', 'cloudflare');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake(['api.cloudflare.com/*' => Http::response([], 500)]);

        $server = Server::factory()->create([
            'hostname' => 'edge-1.stream.example.org',
            'status' => ServerStatusEnum::DEPROVISIONING,
        ]);

        (new DeleteDnsRecordJob($server))->handle(app(DnsManager::class));

        // Reaching here at all is the assertion: a throw would have broken the chain.
        $this->assertTrue(true);
    }

    /**
     * The backfill's guess is only worth pinning when it is a guess at something.
     *
     * `none` is what an installation whose DNS settings this release could not recognise
     * resolves to, and stamping that onto every row would make every existing record
     * undeleteable with no way back but SQL. An unstamped row falls back to the
     * configured driver when it is read, which is the same answer without the trap.
     */
    public function test_the_backfill_never_pins_a_row_to_no_driver(): void
    {
        Config::set('dns.driver', 'none');

        $server = Server::factory()->create(['hostname' => 'edge-old.stream.example.org']);

        (require database_path('migrations/2026_08_30_100000_record_dns_provider_on_servers.php'))->up();

        $this->assertNull($server->fresh()->dnsProvider());

        // And the read falls back to whatever is configured, so nothing is stranded.
        Config::set('dns.driver', 'cloudflare');

        $this->assertSame('cloudflare', app(DnsManager::class)->forRecord($server->fresh()->dnsProvider())->name());
    }

    public function test_the_backfill_pins_a_driver_it_can_name(): void
    {
        Config::set('dns.driver', 'cloudflare');

        $server = Server::factory()->create(['hostname' => 'edge-old.stream.example.org']);

        (require database_path('migrations/2026_08_30_100000_record_dns_provider_on_servers.php'))->up();

        $this->assertSame('cloudflare', $server->fresh()->dnsProvider());
        $this->assertSame('stream.example.org', $server->fresh()->dnsZone());
    }

    /**
     * A driver name a release has since dropped must not turn every teardown into a
     * failed job.
     */
    public function test_an_unknown_driver_falls_back_to_writing_nothing(): void
    {
        $this->assertSame('none', app(DnsManager::class)->forRecord('route53')->name());
    }

    public function test_the_pane_saves_with_an_unselected_drivers_required_field_empty(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'manual',
                'dns_driver' => 'none',
                'dns_zone' => 'stream.example.org',
                // Every rfc2136 and cloudflare credential is required, and none of them
                // was on screen. A pane must not fail on a control nobody saw.
                'dns_server' => '',
                'dns_key_name' => '',
                'dns_key_algorithm' => '',
                'dns_key_secret' => '',
                'dns_cloudflare_token' => '',
                'dns_hetzner_token' => '',
                'hetzner_token' => '',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('none', config('dns.driver'));
    }

    /**
     * Every field on the pane has to name the config path it overrides, or a saved value
     * lands under a path nothing reads and the pane is a lie.
     */
    public function test_a_saved_infrastructure_setting_reaches_its_config_path(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'hetzner',
                'hetzner_token' => 'hz-token',
                'hetzner_location' => 'fsn1',
                'dns_driver' => 'none',
                'dns_zone' => 'live.example.org',
                'dns_ttl' => '120',
            ],
        ])->assertSessionHasNoErrors();

        // The overlay is applied at boot and between jobs, never mid-request.
        RuntimeConfig::flush();
        RuntimeConfig::apply();

        $this->assertSame('live.example.org', config('dns.zone'));
        $this->assertSame(120, config('dns.ttl'));
        $this->assertSame('hetzner', config('stream.server.provider'));
        $this->assertSame('fsn1', config('stream.server.location'));
    }

    /**
     * Switching driver must not throw away the driver being switched away from.
     *
     * A field that is off screen posts blank, and a blank non-secret used to delete its
     * stored row - so saving the pane with cloudflare selected wiped the nsupdate server,
     * key name and algorithm, and switching back offered an empty form.
     */
    public function test_a_hidden_drivers_settings_survive_a_switch_and_a_save(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'hetzner',
                'hetzner_token' => 'hz-token',
                'hetzner_location' => 'hel1',
                'dns_driver' => 'rfc2136',
                'dns_zone' => 'stream.example.org',
                'dns_server' => 'ns1.example.org',
                'dns_key_name' => 'stream-ddns',
                'dns_key_algorithm' => 'hmac-sha512',
                'dns_key_secret' => 'c2VjcmV0LWtleQ==',
            ],
        ])->assertSessionHasNoErrors();

        // Now cloudflare, with every rfc2136 control off screen and posting blank.
        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'manual',
                'hetzner_token' => '',
                'hetzner_location' => '',
                'dns_driver' => 'cloudflare',
                'dns_cloudflare_token' => 'cf-token',
                'dns_zone' => 'stream.example.org',
                'dns_server' => '',
                'dns_key_name' => '',
                'dns_key_algorithm' => '',
                'dns_key_secret' => '',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branding_settings', ['key' => 'dns_server', 'value' => 'ns1.example.org']);
        $this->assertDatabaseHas('branding_settings', ['key' => 'dns_key_algorithm', 'value' => 'hmac-sha512']);
        $this->assertDatabaseHas('branding_settings', ['key' => 'hetzner_location', 'value' => 'hel1']);
        // The secret survives too, and it was never sent to the page to post back.
        $this->assertDatabaseHas('branding_settings', ['key' => 'dns_key_secret']);

        RuntimeConfig::flush();
        RuntimeConfig::apply();

        $this->assertSame('cloudflare', config('dns.driver'));
        $this->assertSame('ns1.example.org', config('dns.server'));
        $this->assertSame('hel1', config('stream.server.location'));
    }

    public function test_the_pane_still_requires_the_selected_drivers_credentials(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'manual',
                'dns_driver' => 'rfc2136',
                'dns_zone' => 'stream.example.org',
                'dns_server' => '',
                'dns_key_name' => '',
                'dns_key_algorithm' => '',
                'dns_key_secret' => '',
            ],
        ])->assertSessionHasErrors(['values.dns_server', 'values.dns_key_name']);
    }

    /**
     * A ShellCommand that records rather than runs, so nsupdate and dig can be asserted
     * on without a name server to talk to.
     */
    /**
     * The test button reaches the same key file the pane writes, so it must not be a way
     * past the pane's own rules. The algorithm is interpolated as `algorithm %s;`.
     */
    public function test_the_test_button_refuses_an_algorithm_the_pane_would(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings.group', 'infrastructure'))
            ->post(route('manage.settings.dns.test'), [
                'driver' => 'rfc2136',
                'zone' => 'stream.example.org',
                'server' => 'ns1.example.org',
                'key_algorithm' => "hmac-sha256;\n\tsecret \"x\";",
            ])
            ->assertSessionHasErrors('key_algorithm');
    }

    public function test_the_test_button_refuses_a_secret_that_is_not_base64(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings.group', 'infrastructure'))
            ->post(route('manage.settings.dns.test'), [
                'driver' => 'rfc2136',
                'zone' => 'stream.example.org',
                'server' => 'ns1.example.org',
                'key_secret' => 'abc"; };  key "other" { secret "x',
            ])
            ->assertSessionHasErrors('key_secret');
    }

    /**
     * A TSIG secret is base64 and goes into `secret "%s";`, so a double quote closes the
     * clause early and whatever follows parses as further key config. It was the one
     * value on that path with no shape at all.
     */
    public function test_a_secret_that_is_not_base64_never_reaches_the_key_file(): void
    {
        $service = new DnsKeyService('k', 'hmac-sha256', 'abc"; }; key "other" { secret "x', 'ns1.example.org', 'stream.example.org');

        $this->expectException(\InvalidArgumentException::class);

        $service->generateKeyFile();
    }

    public function test_the_pane_refuses_a_secret_that_is_not_base64(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('manage.settings.update', 'infrastructure'), [
            'values' => [
                'cloud_driver' => 'manual',
                'dns_driver' => 'rfc2136',
                'dns_zone' => 'stream.example.org',
                'dns_server' => 'ns1.example.org',
                'dns_key_name' => 'stream-ddns',
                'dns_key_algorithm' => 'hmac-sha256',
                'dns_key_secret' => 'not base64!',
            ],
        ])->assertSessionHasErrors('values.dns_key_secret');
    }

    /**
     * A payload that leaves the controlling field out resolves it from what is stored
     * rather than assuming the field is on screen.
     *
     * Assuming visible let a hand-crafted partial write clear a hidden field's row, and
     * the only thing containing it was that every controlling field happens to carry
     * `required` so group validation rejects such a payload first - an invariant nothing
     * stated and nothing enforced.
     */
    public function test_an_omitted_driver_is_resolved_from_what_is_stored(): void
    {
        $settings = app(Settings::class);

        $settings->save([
            'dns_driver' => 'rfc2136',
            'dns_server' => 'ns1.example.org',
        ]);

        // Now cloudflare is what is stored, and this payload does not mention it.
        $settings->save(['dns_driver' => 'cloudflare']);
        $settings->save(['dns_server' => '']);

        $this->assertDatabaseHas('branding_settings', ['key' => 'dns_server', 'value' => 'ns1.example.org']);
    }

    /**
     * The same omission the other way round: with rfc2136 stored, the field is genuinely
     * on screen and a blank really does mean cleared.
     */
    public function test_an_omitted_driver_still_lets_a_visible_field_be_cleared(): void
    {
        $settings = app(Settings::class);

        $settings->save([
            'dns_driver' => 'rfc2136',
            'dns_server' => 'ns1.example.org',
        ]);

        $settings->save(['dns_server' => '']);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'dns_server']);
    }

    private function fakeShell(array $answers = []): object
    {
        $fake = new class($answers) extends ShellCommand
        {
            /** @var array<int, array{cmd: string, input: string|null}> */
            public array $calls = [];

            public function __construct(private readonly array $answers) {}

            public function run(string $cmd, ?string $input = null): string
            {
                $this->calls[] = ['cmd' => $cmd, 'input' => $input];

                foreach ($this->answers as $needle => $answer) {
                    if (str_contains($cmd, $needle)) {
                        return $answer;
                    }
                }

                return '';
            }

            public function lastCommand(): string
            {
                return end($this->calls)['cmd'];
            }

            public function lastInput(): string
            {
                return (string) end($this->calls)['input'];
            }
        };

        $this->swap(ShellCommand::class, $fake);

        Config::set('dns.server', 'ns1.example.org');
        Config::set('dns.key_name', 'stream-ddns');
        Config::set('dns.key_algorithm', 'hmac-sha256');
        Config::set('dns.key_secret', 'c2VjcmV0LWtleQ==');

        return $fake;
    }

    private function driver(string $name): DnsProvider
    {
        return app(DnsManager::class)->driver($name);
    }

    /**
     * Kept so the shared DriverCheck shape is exercised by name rather than only
     * through a driver that happens to return it.
     */
    public function test_the_null_driver_reports_that_it_writes_nothing(): void
    {
        $check = $this->driver('none')->check();

        $this->assertInstanceOf(DriverCheck::class, $check);
        $this->assertTrue($check->ok);
    }
}
