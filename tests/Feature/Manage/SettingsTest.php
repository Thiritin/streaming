<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Services\BrandingService;
use App\Support\Manage\Settings;
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
            'footer_links' => [
                ['label' => 'Support', 'url' => 'https://help.example.test'],
                ['label' => 'Privacy', 'url' => 'https://help.example.test/privacy'],
            ],
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
            ->put(route('manage.settings.update'), $this->payload(['identity_register_url' => 'not a url']))
            ->assertSessionHasErrors('values.identity_register_url');
    }

    public function test_footer_links_are_stored_as_an_ordered_list(): void
    {
        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload([
                'footer_links' => [
                    ['label' => 'Code of Conduct', 'url' => 'https://example.test/coc'],
                    ['label' => 'Support', 'url' => 'https://example.test/help'],
                    ['label' => 'Privacy', 'url' => 'https://example.test/privacy'],
                ],
            ]));

        $this->assertSame([
            ['label' => 'Code of Conduct', 'url' => 'https://example.test/coc'],
            ['label' => 'Support', 'url' => 'https://example.test/help'],
            ['label' => 'Privacy', 'url' => 'https://example.test/privacy'],
        ], app(BrandingService::class)->footerLinks());
    }

    public function test_a_footer_link_needs_both_a_title_and_a_valid_url(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->put(route('manage.settings.update'), $this->payload([
                'footer_links' => [['label' => 'Broken', 'url' => 'not a url']],
            ]))
            ->assertSessionHasErrors('values.footer_links.0.url');

        $this->actingAs($this->admin)
            ->from(route('manage.settings'))
            ->put(route('manage.settings.update'), $this->payload([
                'footer_links' => [['label' => '', 'url' => 'https://example.test']],
            ]))
            ->assertSessionHasErrors('values.footer_links.0.label');
    }

    public function test_no_footer_links_means_no_row_and_no_stored_value(): void
    {
        BrandingSetting::setValue('footer_links', json_encode([
            ['label' => 'Support', 'url' => 'https://example.test/help'],
        ]));

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['footer_links' => []]));

        $this->assertDatabaseMissing('branding_settings', ['key' => 'footer_links']);
        $this->assertSame([], app(BrandingService::class)->footerLinks());
        $this->assertSame([], app(BrandingService::class)->forFrontend()['links']);
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

    public function test_saving_a_value_equal_to_the_default_drops_the_row(): void
    {
        BrandingSetting::setValue('login_headline', 'Something else');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload([
                'login_headline' => config('branding.login_headline'),
            ]));

        // Not merely equal to the default: no row at all, so a later change to
        // the shipped default applies instead of being shadowed forever.
        $this->assertDatabaseMissing('branding_settings', ['key' => 'login_headline']);
        $this->assertSame(config('branding.login_headline'), BrandingSetting::getValue('login_headline'));
    }

    public function test_clearing_an_optional_field_whose_default_is_empty_stores_nothing(): void
    {
        // login_eyebrow ships empty, so clearing it is a no-op that should not
        // leave a row behind either.
        BrandingSetting::setValue('login_eyebrow', 'Testcon');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['login_eyebrow' => '']));

        $this->assertDatabaseMissing('branding_settings', ['key' => 'login_eyebrow']);
    }

    public function test_clearing_optional_copy_puts_the_shipped_default_back(): void
    {
        // login_tagline ships with wording. Emptying the input is "I did not
        // choose one", not "I chose nothing": ConvertEmptyStringsToNull turns it
        // into null on the way in and a null row reads as unset, so the only
        // consistent outcome is the default.
        $this->assertNotSame('', (string) config('branding.login_tagline'));

        BrandingSetting::setValue('login_tagline', 'Custom wording');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['login_tagline' => '']));

        $this->assertDatabaseMissing('branding_settings', ['key' => 'login_tagline']);
        $this->assertSame(config('branding.login_tagline'), BrandingSetting::getValue('login_tagline'));
    }

    public function test_a_secret_is_never_sent_to_the_browser(): void
    {
        BrandingSetting::setValue('pretalx_token', 'secret-token');

        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertSuccessful()
            ->assertDontSee('secret-token')
            ->assertInertia(function (Assert $page) {
                $field = collect($page->toArray()['props']['groups'])
                    ->flatMap(fn (array $group) => $group['fields'])
                    ->firstWhere('key', 'pretalx_token');

                // Masked rather than empty, so the field shows that something is stored.
                $this->assertSame(Settings::MASK_SECRET, $field['value']);
                $this->assertTrue($field['hasValue']);
            });
    }

    public function test_a_blank_or_masked_secret_keeps_the_stored_one(): void
    {
        // The page never receives the token, so an untouched form must not wipe it -
        // neither when it posts nothing nor when it posts the mask back.
        BrandingSetting::setValue('pretalx_token', 'secret-token');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['pretalx_token' => '']));

        $this->assertSame('secret-token', BrandingSetting::getValue('pretalx_token'));

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['pretalx_token' => Settings::MASK_SECRET]));

        $this->assertSame('secret-token', BrandingSetting::getValue('pretalx_token'));
    }

    public function test_a_secret_is_replaced_when_one_is_typed_and_cleared_on_request(): void
    {
        BrandingSetting::setValue('pretalx_token', 'secret-token');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['pretalx_token' => 'new-token']));

        $this->assertSame('new-token', BrandingSetting::getValue('pretalx_token'));

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update'), $this->payload(['pretalx_token' => Settings::CLEAR_SECRET]));

        $this->assertDatabaseMissing('branding_settings', ['key' => 'pretalx_token']);
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
