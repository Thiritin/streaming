<?php

namespace Tests\Feature;

use App\Models\BrandingSetting;
use App\Support\Manage\Settings;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RuntimeConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A registry of our own, so the contract can be tested without waiting for a
     * pane to be built around it.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function registry(array $fields): void
    {
        config()->set('settings.groups', [
            [
                'key' => 'testing',
                'label' => 'Testing',
                'fields' => $fields,
            ],
        ]);

        RuntimeConfig::flush();
    }

    public function test_a_secure_value_is_not_readable_as_plaintext_but_round_trips(): void
    {
        BrandingSetting::setValue('oidc_client_secret', 'sup3r-secret', null, true);

        $row = DB::table('branding_settings')->where('key', 'oidc_client_secret')->first();

        $this->assertNotSame('sup3r-secret', $row->value);
        $this->assertStringNotContainsString('sup3r-secret', $row->value);
        $this->assertTrue((bool) $row->encrypted);

        $this->assertSame('sup3r-secret', BrandingSetting::getValue('oidc_client_secret'));
    }

    public function test_a_secure_value_that_cannot_be_decrypted_falls_back_to_the_default(): void
    {
        BrandingSetting::setValue('oidc_client_secret', 'sup3r-secret', null, true);

        DB::table('branding_settings')
            ->where('key', 'oidc_client_secret')
            ->update(['value' => 'not-a-ciphertext']);

        $this->assertSame('shipped', BrandingSetting::getValue('oidc_client_secret', 'shipped'));
    }

    public function test_a_saved_value_overrides_the_config_path_it_names(): void
    {
        $this->registry([
            [
                'key' => 'oidc_url',
                'label' => 'OIDC URL',
                'type' => 'text',
                'config' => 'services.oidc.url',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        config()->set('services.oidc.url', 'https://shipped.example.org');
        config()->set('services.oidc.client_id', 'shipped-client');

        BrandingSetting::setValue('oidc_url', 'https://saved.example.org');

        RuntimeConfig::apply();

        $this->assertSame('https://saved.example.org', config('services.oidc.url'));
        // The surrounding array is merged into, not replaced.
        $this->assertSame('shipped-client', config('services.oidc.client_id'));
    }

    public function test_a_nested_disk_path_is_merged_rather_than_replaced(): void
    {
        $this->registry([
            [
                'key' => 'dvr_key',
                'label' => 'DVR key',
                'type' => 'text',
                'config' => 'filesystems.disks.dvr.key',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        BrandingSetting::setValue('dvr_key', 'AKIA-SAVED');

        RuntimeConfig::apply();

        $this->assertSame('AKIA-SAVED', config('filesystems.disks.dvr.key'));
        $this->assertSame('s3', config('filesystems.disks.dvr.driver'));
    }

    public function test_a_field_without_a_config_key_falls_back_to_store_and_key(): void
    {
        $this->registry([
            [
                'key' => 'pretalx_event',
                'label' => 'Event slug',
                'type' => 'text',
                'store' => 'pretalx',
                'rules' => ['nullable', 'string'],
            ],
            [
                'key' => 'site_name',
                'label' => 'Site name',
                'type' => 'text',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        BrandingSetting::setValue('pretalx_event', 'my-con-2026');
        BrandingSetting::setValue('site_name', 'Saved Site');

        RuntimeConfig::apply();

        $this->assertSame('my-con-2026', config('pretalx.pretalx_event'));
        // No store either, so the registry's own default store answers.
        $this->assertSame('Saved Site', config('branding.site_name'));
    }

    public function test_casts_produce_typed_values(): void
    {
        $this->registry([
            [
                'key' => 'edge_max_clients',
                'label' => 'Max clients',
                'type' => 'text',
                'config' => 'stream.edge.max_clients',
                'cast' => 'int',
                'rules' => ['nullable', 'integer'],
            ],
            [
                'key' => 'chat',
                'label' => 'Chat',
                'type' => 'toggle',
                'store' => 'features',
                'rules' => ['boolean'],
            ],
        ]);

        BrandingSetting::setValue('edge_max_clients', '900');
        BrandingSetting::setValue('chat', '0');

        RuntimeConfig::apply();

        $this->assertSame(900, config('stream.edge.max_clients'));
        $this->assertFalse(config('features.chat'));
    }

    public function test_an_empty_saved_value_is_no_override(): void
    {
        $this->registry([
            [
                'key' => 'site_name',
                'label' => 'Site name',
                'type' => 'text',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        config()->set('branding.site_name', 'Shipped');

        BrandingSetting::setValue('site_name', '');

        RuntimeConfig::apply();

        $this->assertSame('Shipped', config('branding.site_name'));
    }

    public function test_a_secure_field_is_decrypted_into_config(): void
    {
        $this->registry([
            [
                'key' => 'oidc_client_secret',
                'label' => 'Client secret',
                'type' => 'password',
                'config' => 'services.oidc.client_secret',
                'secure' => true,
                'rules' => ['nullable', 'string'],
            ],
        ]);

        app(Settings::class)->save(['oidc_client_secret' => 'sup3r-secret']);

        $this->assertTrue((bool) DB::table('branding_settings')->where('key', 'oidc_client_secret')->value('encrypted'));

        RuntimeConfig::apply();

        $this->assertSame('sup3r-secret', config('services.oidc.client_secret'));
    }

    public function test_an_overridden_disk_is_purged_from_the_filesystem_manager(): void
    {
        $this->registry([
            [
                'key' => 'dvr_bucket',
                'label' => 'DVR bucket',
                'type' => 'text',
                'config' => 'filesystems.disks.dvr.bucket',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        config()->set('filesystems.disks.dvr.bucket', 'shipped-bucket');

        // Resolve it first, which is what leaves the old credentials memoised.
        $this->assertSame('shipped-bucket', Storage::disk('dvr')->getConfig()['bucket']);

        BrandingSetting::setValue('dvr_bucket', 'saved-bucket');

        RuntimeConfig::apply();

        $this->assertSame('saved-bucket', Storage::disk('dvr')->getConfig()['bucket']);
    }

    public function test_a_default_follows_the_config_path_a_field_names(): void
    {
        $this->registry([
            [
                'key' => 'auth_required',
                'label' => 'Require sign-in',
                'type' => 'toggle',
                'config' => 'auth.required',
                'rules' => ['boolean'],
            ],
        ]);

        config()->set('auth.required', true);

        $settings = app(Settings::class);
        $field = $settings->group('testing')['fields'][0];

        // Read from auth.required, not from a second file keyed auth.auth_required.
        $this->assertTrue($field['default']);
        $this->assertTrue($field['value']);

        // Saving the default stores nothing.
        $settings->save(['auth_required' => true]);
        $this->assertDatabaseMissing('branding_settings', ['key' => 'auth_required']);

        // Saving the opposite overrides the path as a real boolean.
        $settings->save(['auth_required' => false]);
        $this->assertDatabaseHas('branding_settings', ['key' => 'auth_required', 'value' => '0']);

        RuntimeConfig::apply();

        $this->assertFalse(config('auth.required'));

        // And back to the default deletes the row again, which is the half that breaks
        // when the save side and the display side disagree about what the default is.
        $settings->save(['auth_required' => true]);
        $this->assertDatabaseMissing('branding_settings', ['key' => 'auth_required']);
    }

    public function test_a_saved_value_survives_a_missing_encrypted_column(): void
    {
        $this->registry([
            [
                'key' => 'site_name',
                'label' => 'Site name',
                'type' => 'text',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        config()->set('branding.site_name', 'Shipped');

        BrandingSetting::setValue('site_name', 'Saved');

        // Code deployed ahead of its migration.
        Schema::table('branding_settings', fn ($table) => $table->dropColumn('encrypted'));
        RuntimeConfig::flush();
        Cache::forget('branding_setting_site_name');

        $this->assertSame('Saved', BrandingSetting::getValue('site_name'));

        RuntimeConfig::apply();

        $this->assertSame('Saved', config('branding.site_name'));
    }

    public function test_config_cache_does_not_write_a_secure_value_to_disk(): void
    {
        $this->registry([
            [
                'key' => 'oidc_client_secret',
                'label' => 'Client secret',
                'type' => 'password',
                'config' => 'services.oidc.client_secret',
                'secure' => true,
                'rules' => ['nullable', 'string'],
            ],
        ]);

        app(Settings::class)->save(['oidc_client_secret' => 'sup3r-secret']);

        RuntimeConfig::apply();
        $this->assertSame('sup3r-secret', config('services.oidc.client_secret'));

        $cached = $this->app->getCachedConfigPath();

        try {
            Artisan::call('config:cache');

            $this->assertFileExists($cached);
            $this->assertStringNotContainsString('sup3r-secret', file_get_contents($cached));

            // The overlay is off the live repository too, not just off the dump.
            $this->assertNotSame('sup3r-secret', config('services.oidc.client_secret'));
        } finally {
            Artisan::call('config:clear');
            RuntimeConfig::resume();
        }

        $this->assertFileDoesNotExist($cached);
    }

    public function test_deleting_a_row_puts_the_shipped_value_back(): void
    {
        $this->registry([
            [
                'key' => 'site_name',
                'label' => 'Site name',
                'type' => 'text',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        config()->set('branding.site_name', 'Shipped');

        BrandingSetting::setValue('site_name', 'Saved');
        RuntimeConfig::apply();

        $this->assertSame('Saved', config('branding.site_name'));

        BrandingSetting::where('key', 'site_name')->get()->each->delete();
        RuntimeConfig::apply();

        $this->assertSame('Shipped', config('branding.site_name'));
    }

    public function test_apply_no_ops_when_the_table_is_missing(): void
    {
        $this->registry([
            [
                'key' => 'site_name',
                'label' => 'Site name',
                'type' => 'text',
                'rules' => ['nullable', 'string'],
            ],
        ]);

        config()->set('branding.site_name', 'Shipped');

        BrandingSetting::setValue('site_name', 'Saved');

        Schema::drop('branding_settings');
        RuntimeConfig::flush();

        RuntimeConfig::apply();

        $this->assertSame('Shipped', config('branding.site_name'));
    }
}
