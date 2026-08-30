<?php

namespace Tests\Feature\Commands;

use App\Models\BrandingSetting;
use App\Support\EnvImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-shot import that moves a deployment's `.env` into the settings table.
 *
 * Every test starts from an environment with none of the variables EnvImport knows about
 * set, whatever the machine running the suite has in its own `.env`, and puts back only
 * what it is about. Otherwise a developer with HETZNER_TOKEN in their environment and one
 * without would be running two different suites.
 *
 * Setting a variable is only half of it: the application is already booted, so the config
 * repository holds what it read at boot. Each test sets both, which is also exactly the
 * state a deploy is in - the value in the environment and the value in config being the
 * same thing.
 */
class SettingsImportEnvTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: string|null, 1: string|null, 2: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (EnvImport::variables() as $var) {
            $this->saved[$var] = [$_ENV[$var] ?? null, $_SERVER[$var] ?? null, getenv($var)];

            unset($_ENV[$var], $_SERVER[$var]);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $var => [$env, $server, $put]) {
            unset($_ENV[$var], $_SERVER[$var]);
            putenv($var);

            if ($env !== null) {
                $_ENV[$var] = $env;
            }

            if ($server !== null) {
                $_SERVER[$var] = $server;
            }

            if ($put !== false) {
                putenv("{$var}={$put}");
            }
        }

        parent::tearDown();
    }

    private function env(string $var, string $value): void
    {
        $_ENV[$var] = $value;
        $_SERVER[$var] = $value;
        putenv("{$var}={$value}");
    }

    /**
     * The environment supplying a value, and the config repository already holding it,
     * which is the state a booted application is in.
     */
    private function supplies(string $var, string $value, string $path, mixed $config = null): void
    {
        $this->env($var, $value);

        config([$path => $config ?? $value]);
    }

    private function row(string $key): ?array
    {
        foreach (EnvImport::plan() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    public function test_the_dry_run_is_the_default_and_writes_nothing(): void
    {
        $this->supplies('CHAT_MAX_TRIES', '15', 'chat.default.maxTries', 15);

        $this->artisan('settings:import-env')
            ->expectsOutputToContain('Dry run.')
            ->assertSuccessful();

        $this->assertDatabaseCount('branding_settings', 0);

        // And with the flag spelled out, which is what an operator will type.
        $this->artisan('settings:import-env', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('branding_settings', 0);
    }

    public function test_dry_run_wins_over_write(): void
    {
        $this->supplies('CHAT_MAX_TRIES', '15', 'chat.default.maxTries', 15);

        $this->artisan('settings:import-env', ['--write' => true, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('branding_settings', 0);
    }

    public function test_write_stores_the_value_the_environment_supplies(): void
    {
        $this->supplies('CHAT_MAX_TRIES', '15', 'chat.default.maxTries', 15);

        $this->artisan('settings:import-env', ['--write' => true])->assertSuccessful();

        $this->assertSame('15', BrandingSetting::where('key', 'chat_max_tries')->value('value'));
    }

    /**
     * A row in the table is a decision somebody made in the panel. The environment does
     * not get to argue with it.
     */
    public function test_an_existing_row_is_never_overwritten(): void
    {
        BrandingSetting::setValue('chat_max_tries', '3');

        $this->supplies('CHAT_MAX_TRIES', '15', 'chat.default.maxTries', 15);

        $this->assertSame('saved', $this->row('chat_max_tries')['reason']);

        $this->artisan('settings:import-env', ['--write' => true])->assertSuccessful();

        $this->assertSame('3', BrandingSetting::where('key', 'chat_max_tries')->value('value'));
    }

    public function test_a_secure_field_lands_encrypted_and_is_never_printed(): void
    {
        $this->supplies('HETZNER_TOKEN', 'a-cloud-token', 'services.hetzner.token');

        $this->artisan('settings:import-env', ['--write' => true])
            ->doesntExpectOutputToContain('a-cloud-token')
            ->assertSuccessful();

        $stored = DB::table('branding_settings')->where('key', 'hetzner_token')->first();

        $this->assertTrue((bool) $stored->encrypted);
        $this->assertNotSame('a-cloud-token', $stored->value);
        $this->assertSame('a-cloud-token', Crypt::decryptString($stored->value));
    }

    /**
     * The whole trap this command has to avoid: while the variable is still in `.env`,
     * the field's shipped default *is* the value being imported, so Settings::save()
     * would delete every row it wrote. The comparison has to be against what the config
     * file answers with the variable out of the way.
     */
    public function test_a_value_equal_to_the_shipped_default_is_skipped(): void
    {
        // config/chat.php ships env('CHAT_MAX_TRIES', 8).
        $this->supplies('CHAT_MAX_TRIES', '8', 'chat.default.maxTries', 8);

        $this->assertSame('default', $this->row('chat_max_tries')['reason']);

        $this->artisan('settings:import-env', ['--write' => true])->assertSuccessful();

        $this->assertDatabaseMissing('branding_settings', ['key' => 'chat_max_tries']);
    }

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $this->supplies('CHAT_MAX_TRIES', '15', 'chat.default.maxTries', 15);
        $this->supplies('HETZNER_TOKEN', 'a-cloud-token', 'services.hetzner.token');

        $this->artisan('settings:import-env', ['--write' => true])->assertSuccessful();

        $before = DB::table('branding_settings')->orderBy('key')->get()->toArray();

        $this->artisan('settings:import-env', ['--write' => true])->assertSuccessful();

        $after = DB::table('branding_settings')->orderBy('key')->get()->toArray();

        $this->assertEquals($before, $after);

        // Every field is now saved, so the second run has nothing left to do.
        $this->assertSame([], array_filter(EnvImport::plan(), fn (array $row) => $row['action'] === 'import'));
    }

    /**
     * (a) The environment still feeds it, so an unmigrated deploy behaves the same and
     * the import is a tidy-up.
     */
    public function test_a_field_the_environment_still_feeds_is_imported(): void
    {
        $this->supplies('ARCHIVE_URL_TTL', '3600', 'stream.archive_url_ttl', 3600);

        $row = $this->row('archive_url_ttl');

        $this->assertSame(EnvImport::ENV, $row['class']);
        $this->assertSame('import', $row['action']);
        $this->assertSame('ARCHIVE_URL_TTL', $row['var']);
    }

    /**
     * (b) The config path moved out from under the old variable, so the import is the
     * fix rather than a tidy-up and the command has to say so.
     */
    public function test_a_moved_field_is_imported_and_said_loudly(): void
    {
        $this->supplies('STREAM_SYSTEM_STREAMKEY', 'the-system-key', 'stream.system_streamkey');

        $row = $this->row('system_streamkey');

        $this->assertSame(EnvImport::MOVED, $row['class']);
        $this->assertSame('import', $row['action']);

        $this->artisan('settings:import-env')
            ->expectsOutputToContain('READ THIS')
            ->expectsOutputToContain('system_streamkey')
            ->doesntExpectOutputToContain('the-system-key')
            ->assertSuccessful();
    }

    /**
     * The older name for the same key is still read, so an installation that never
     * renamed it is imported rather than skipped.
     */
    public function test_the_older_variable_name_supplies_the_streamkey_too(): void
    {
        $this->supplies('STREAM_KEY', 'the-old-key', 'stream.system_streamkey');

        $this->assertSame('STREAM_KEY', $this->row('system_streamkey')['var']);
    }

    /**
     * (c) The feature left config for a table of its own. A migration owns the copy;
     * this command reports it and touches nothing.
     */
    public function test_a_seeded_variable_is_reported_and_never_imported(): void
    {
        $this->env('OIDC_URL', 'https://identity.example.org');

        $this->assertContains('OIDC_URL', EnvImport::seeded()[0]['vars']);

        $this->artisan('settings:import-env', ['--write' => true])
            ->expectsOutputToContain('Handed to a migration')
            ->expectsOutputToContain('auth_providers')
            ->assertSuccessful();

        $this->assertDatabaseCount('branding_settings', 0);
    }

    /**
     * (d) The variable went with the feature it configured.
     */
    public function test_an_obsolete_variable_is_reported_as_obsolete(): void
    {
        $this->env('RTMP_FORWARD', 'rtmp://origin.example.org/live');

        $this->assertArrayHasKey('RTMP_FORWARD', EnvImport::obsolete());

        $this->artisan('settings:import-env')
            ->expectsOutputToContain('Obsolete, safe to delete')
            ->expectsOutputToContain('RTMP_FORWARD')
            ->assertSuccessful();
    }

    /**
     * A field that never had a variable behind it is not the same as one whose variable
     * is unset, and the report keeps them apart.
     */
    public function test_a_panel_only_field_is_classified_as_such(): void
    {
        $row = $this->row('pretalx_token');

        $this->assertSame(EnvImport::PANEL, $row['class']);
        $this->assertSame('panel', $row['reason']);
    }

    /**
     * The DNS driver is inferred from the nsupdate variables rather than set directly,
     * so it has to be pinned before those variables can be deleted.
     */
    public function test_the_inferred_dns_driver_is_imported_from_the_nsupdate_variables(): void
    {
        $this->env('DNS_SERVER', 'ns.example.org');
        $this->env('DNS_DRIVER', 'rfc2136');

        config(['dns.driver' => 'rfc2136', 'dns.server' => 'ns.example.org']);

        $this->assertSame('import', $this->row('dns_driver')['action']);
        $this->assertSame('import', $this->row('dns_server')['action']);
    }

    public function test_a_variable_still_read_outside_this_app_is_not_offered_for_deletion(): void
    {
        $this->supplies('HLS_VIEWER_SECRET', 'a-viewer-secret', 'stream.token.viewer_secret');
        $this->supplies('ARCHIVE_URL_TTL', '3600', 'stream.archive_url_ttl', 3600);

        $imports = array_filter(EnvImport::plan(), fn (array $row) => $row['action'] === 'import');
        $retirable = EnvImport::retirable($imports);

        $this->assertContains('ARCHIVE_URL_TTL', $retirable);
        $this->assertNotContains('HLS_VIEWER_SECRET', $retirable);
    }

    /**
     * The registry and the classification have to stay in step. A knob added without an
     * entry would otherwise be skipped by the very command that exists to find it.
     */
    public function test_every_registry_field_is_classified(): void
    {
        $this->assertSame([], EnvImport::unclassified());
        $this->assertSame([], EnvImport::stale());
    }

    public function test_an_unclassified_field_fails_rather_than_being_guessed_at(): void
    {
        config(['settings.groups' => [...config('settings.groups'), [
            'key' => 'scratch',
            'label' => 'Scratch',
            'fields' => [
                ['key' => 'a_knob_nobody_classified', 'label' => 'A knob', 'type' => 'text'],
            ],
        ]]]);

        $this->artisan('settings:import-env')
            ->expectsOutputToContain('a_knob_nobody_classified')
            ->assertFailed();
    }
}
