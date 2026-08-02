<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Services\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['values' => array_merge([
            'convention_name' => 'Testcon',
            'site_name' => 'Testcon Streaming',
            'identity_name' => 'Testcon Identity',
            'identity_register_url' => 'https://id.example.test/register',
            'identity_logout_url' => 'https://id.example.test/logout',
            'login_eyebrow' => 'Testcon',
            'login_headline' => 'Watch live',
            'login_tagline' => 'Everyone welcome',
            'login_button_label' => 'Sign in',
            'login_body' => 'You only need an account.',
            'primary_color' => '#ff8800',
            'logo_path' => 'branding/logo.png',
            'login_background_image' => '',
            'login_background_video' => '',
            'support_url' => 'https://help.example.test',
            'imprint_url' => 'https://help.example.test/imprint',
            'privacy_url' => 'https://help.example.test/privacy',
        ], $overrides)];
    }

    public function test_the_page_lists_every_registered_group_and_field(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $page->component('Manage/Settings');

                $groups = collect($page->toArray()['props']['groups']);

                $this->assertSame(
                    collect(config('settings.groups'))->pluck('key')->all(),
                    $groups->pluck('key')->all(),
                );

                $keys = $groups->flatMap(fn (array $group) => collect($group['fields'])->pluck('key'))->all();

                foreach (array_keys(BrandingService::EDITABLE) as $editable) {
                    $this->assertContains($editable, $keys, "[{$editable}] is not editable any more.");
                }
            });
    }

    public function test_a_field_reports_the_shipped_default_until_it_is_overridden(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertInertia(function (Assert $page) {
                $field = $this->field($page, 'site_name');

                $this->assertSame(config('branding.site_name'), $field['value']);
                $this->assertSame(config('branding.site_name'), $field['default']);
                $this->assertFalse($field['overridden']);
            });

        BrandingSetting::setValue('site_name', 'Something else');

        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertInertia(function (Assert $page) {
                $field = $this->field($page, 'site_name');

                $this->assertSame('Something else', $field['value']);
                $this->assertSame(config('branding.site_name'), $field['default']);
                $this->assertTrue($field['overridden']);
            });
    }

    public function test_saving_stores_the_values(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->put(route('manage.settings.update'), $this->payload())
            ->assertRedirect(route('manage.settings'));

        $this->assertSame('Testcon', BrandingSetting::getValue('convention_name'));
        $this->assertSame('#ff8800', BrandingSetting::getValue('primary_color'));
        $this->assertSame('Watch live', app(BrandingService::class)->get('login_headline'));
    }

    public function test_the_saved_values_reach_the_public_frontend_shape(): void
    {
        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['login_headline' => 'Livestream 2026']));

        $branding = app(BrandingService::class)->forFrontend();

        $this->assertSame('Livestream 2026', $branding['login']['headline']);
        $this->assertSame('Testcon', $branding['conventionName']);
    }

    public function test_required_copy_cannot_be_emptied(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->put(route('manage.settings.update'), $this->payload(['login_headline' => '']))
            ->assertSessionHasErrors('values.login_headline');
    }

    public function test_a_url_field_rejects_something_that_is_not_a_url(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->put(route('manage.settings.update'), $this->payload(['support_url' => 'not a url']))
            ->assertSessionHasErrors('values.support_url');
    }

    public function test_the_accent_colour_must_be_a_hex_value(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->put(route('manage.settings.update'), $this->payload(['primary_color' => 'rebeccapurple']))
            ->assertSessionHasErrors('values.primary_color');
    }

    public function test_an_unregistered_key_is_ignored_rather_than_stored(): void
    {
        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['rtmp_host' => 'evil.example.test']));

        $this->assertDatabaseMissing('branding_settings', ['key' => 'rtmp_host']);
    }

    public function test_resetting_drops_every_saved_value(): void
    {
        BrandingSetting::setValue('site_name', 'Something else');

        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->post(route('manage.settings.reset'))
            ->assertRedirect(route('manage.settings'));

        $this->assertDatabaseCount('branding_settings', 0);
        $this->assertSame(config('branding.site_name'), BrandingSetting::getValue('site_name'));
    }

    public function test_only_administrators_can_read_or_change_the_settings(): void
    {
        // The moderator holds the manage gate but not admin.access.
        $this->actingAs($this->moderator)->get(route('manage.settings'))->assertForbidden();

        $this->actingAs($this->moderator)
            ->put(route('manage.settings.update'), $this->payload())
            ->assertForbidden();

        $this->actingAs($this->moderator)->post(route('manage.settings.reset'))->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function field(Assert $page, string $key): array
    {
        return collect($page->toArray()['props']['groups'])
            ->flatMap(fn (array $group) => $group['fields'])
            ->firstWhere('key', $key);
    }
}
