<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Services\BrandingService;
use App\Services\ChatMessageSanitizer;
use App\Support\ControlKey;
use App\Support\Manage\Settings;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use ConfiguresAuthProviders;
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    /**
     * One pane's fields as they currently stand, which is what an untouched form posts.
     *
     * Built from the registry rather than written out, so a pane that gains a required
     * field does not silently start failing every test that saves it.
     *
     * @return array<string, mixed>
     */
    private function values(string $group, array $overrides = []): array
    {
        $fields = app(Settings::class)->group($group)['fields'];

        $values = [];

        foreach ($fields as $field) {
            // A password is never sent to the page, so an untouched form posts nothing
            // for it, which the save side reads as "keep what is stored".
            $values[$field['key']] = $field['type'] === 'password' ? '' : $field['value'];
        }

        return array_merge($values, $overrides);
    }

    /**
     * Save one pane as the administrator.
     */
    private function save(string $group, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('manage.settings.group', $group))
            ->put(route('manage.settings.update', $group), ['values' => $this->values($group, $overrides)]);
    }

    /**
     * One field of the pane on screen.
     *
     * @return array<string, mixed>
     */
    private function field(Assert $page, string $key): array
    {
        return collect($page->toArray()['props']['group']['fields'])->firstWhere('key', $key);
    }

    /**
     * One card of the pane on screen.
     *
     * @return array<string, mixed>
     */
    private function card(Assert $page, string $key): array
    {
        return collect($page->toArray()['props']['group']['cards'])->firstWhere('key', $key);
    }

    public function test_the_menu_lists_every_registered_pane(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $page->component('Manage/Settings');

                $navigation = $page->toArray()['props']['navigation'];

                /*
                 * Three sections, in registry order within each. Events and Categories
                 * are settings areas whose contents are rows rather than fields, so they
                 * join Programme by hand; the sign-in providers are rows too but are not
                 * a menu entry at all, being reached from the card on the sign-in pane.
                 */
                $this->assertSame(
                    ['Site', 'Programme', 'System'],
                    collect($navigation['sections'])->pluck('heading')->all(),
                );

                $this->assertSame(
                    [
                        ['Sign-in', 'Branding', 'Announcement', 'Features', 'Chat'],
                        ['Events', 'Categories', 'Pretalx'],
                        ['Streaming', 'Servers', 'Archive storage', 'Tokens and keys', 'Notifications'],
                    ],
                    collect($navigation['sections'])
                        ->map(fn (array $section) => collect($section['items'])->pluck('label')->all())
                        ->all(),
                );

                // Reset is a destructive button pinned under the menu, not a row in it.
                $keys = collect($navigation['sections'])
                    ->flatMap(fn (array $section) => collect($section['items'])->pluck('key'))
                    ->all();

                $this->assertNotContains('reset', $keys);
                $this->assertSame('reset', $navigation['reset']['key']);

                // No blurbs: the heading carries what they were reaching for.
                $this->assertSame([], collect($navigation['sections'])
                    ->flatMap(fn (array $section) => $section['items'])
                    ->filter(fn (array $item) => array_key_exists('blurb', $item))
                    ->all());

                // The bare URL is the first pane rather than a redirect.
                $this->assertSame(config('settings.groups.0.key'), $page->toArray()['props']['group']['key']);
            });
    }

    public function test_the_categories_pane_renders_inside_the_settings_menu(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.categories.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Categories/Index')
                ->where('navigation', fn ($navigation) => collect($navigation['sections'])
                    ->flatMap(fn (array $section) => $section['items'])
                    ->firstWhere('key', 'categories')['url'] === route('manage.categories.index'))
            );
    }

    public function test_every_editable_key_lives_on_a_pane(): void
    {
        $keys = collect(app(Settings::class)->groups())
            ->flatMap(fn (array $group) => collect($group['fields'])->pluck('key'))
            ->all();

        foreach (array_keys(BrandingService::EDITABLE) as $editable) {
            $this->assertContains($editable, $keys, "[{$editable}] is not editable any more.");
        }
    }

    public function test_a_pane_carries_only_its_own_fields(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'identity'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $keys = collect($page->toArray()['props']['group']['fields'])->pluck('key');

                $this->assertContains('login_headline', $keys);
                $this->assertNotContains('control_key', $keys);
            });
    }

    /**
     * A merged pane is cards, and the cards are only layout: the flat field list the
     * save posts is every card's fields in order, so nothing about validating or
     * storing a value learns that cards exist.
     */
    public function test_a_merged_pane_renders_its_cards(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'identity'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $group = $page->toArray()['props']['group'];

                $this->assertSame(
                    ['methods', 'provider_pages', 'login'],
                    collect($group['cards'])->pluck('key')->all(),
                );

                $flat = collect($group['fields'])->pluck('key')->all();

                foreach (['auth_required', 'auth_local', 'auth_oauth2', 'identity_name', 'login_headline'] as $key) {
                    $this->assertContains($key, $flat);
                }

                // Every card's fields are in the flat list, and nothing else is.
                $this->assertSame(
                    collect($group['cards'])->flatMap(fn (array $card) => collect($card['fields'])->pluck('key'))->all(),
                    $flat,
                );
            });
    }

    /**
     * Every field of a merged pane still saves, whichever card it was drawn in.
     */
    public function test_every_field_of_a_merged_pane_still_saves(): void
    {
        $this->save('identity', [
            'identity_name' => 'Example Identity',
            'login_headline' => 'Watch live',
            'identity_name' => 'Example Identity',
            'auth_local' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Example Identity', BrandingSetting::getValue('identity_name'));
        $this->assertSame('Watch live', BrandingSetting::getValue('login_headline'));
        $this->assertSame('1', BrandingSetting::getValue('auth_local'));
    }

    /**
     * A pane that declares no cards is exactly what it was: one flat list and an empty
     * card list, so its page renders the same block it always did.
     */
    public function test_a_pane_with_no_cards_is_unchanged(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'chat'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $group = $page->toArray()['props']['group'];

                $this->assertSame([], $group['cards']);
                $this->assertContains('chat_max_tries', collect($group['fields'])->pluck('key')->all());
            });
    }

    /**
     * The sign-in providers are rows, so they have no registry fields, no menu entry and
     * no list on this pane: the card points at the page they are edited on.
     */
    public function test_the_sign_in_pane_points_at_the_providers_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'identity'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];

                $this->assertSame('providers_link', $this->card($page, 'methods')['render']);
                $this->assertSame(route('manage.providers.index'), $props['providersUrl']);

                // The list itself never reaches this page any more.
                $this->assertArrayNotHasKey('providers', $props);

                $keys = collect($props['navigation']['sections'])
                    ->flatMap(fn (array $section) => collect($section['items'])->pluck('key'))
                    ->all();

                $this->assertNotContains('providers', $keys);
            });
    }

    /**
     * Guest access is asked as its own opposite - the row is "Guest Access", the column
     * is auth_required - so the page shows the inverse and the save turns it back. The
     * table and every reader of config('auth.required') are unchanged.
     */
    public function test_guest_access_is_shown_inverted_and_stored_straight(): void
    {
        config(['auth.required' => true]);

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'identity'))
            ->assertInertia(function (Assert $page) {
                // Sign-in is required, so guests are not allowed: the switch reads off.
                $this->assertFalse($this->field($page, 'auth_required')['value']);
            });

        // Switching the row on means letting guests watch, which is auth_required false.
        $this->save('identity', ['auth_required' => true])->assertSessionHasNoErrors();

        $this->assertSame('0', BrandingSetting::getValue('auth_required'));

        RuntimeConfig::apply();
        $this->assertFalse(config('auth.required'));

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'identity'))
            ->assertInertia(fn (Assert $page) => $this->assertTrue($this->field($page, 'auth_required')['value']));
    }

    /**
     * A pane merged into another one keeps answering: its URL is printed in the admin
     * docs and pasted between operators, so a bookmark must not 404.
     */
    public function test_a_merged_pane_url_redirects_to_the_pane_that_took_it(): void
    {
        foreach (config('settings.moved') as $gone => $target) {
            $this->actingAs($this->admin)
                ->get(route('manage.settings.group', $gone))
                ->assertRedirect(route('manage.settings.group', $target))
                ->assertStatus(301);
        }
    }

    public function test_an_unknown_pane_is_not_there(): void
    {
        $this->actingAs($this->admin)->get(route('manage.settings.group', 'nonsense'))->assertNotFound();

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update', 'nonsense'), ['values' => ['site_name' => 'x']])
            ->assertNotFound();
    }

    public function test_a_field_reports_the_shipped_default_until_it_is_overridden(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'branding'))
            ->assertInertia(function (Assert $page) {
                $field = $this->field($page, 'site_name');

                $this->assertSame(config('branding.site_name'), $field['value']);
                $this->assertSame(config('branding.site_name'), $field['default']);
                $this->assertFalse($field['overridden']);
            });

        BrandingSetting::setValue('site_name', 'Something else');

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'branding'))
            ->assertInertia(function (Assert $page) {
                $field = $this->field($page, 'site_name');

                $this->assertSame('Something else', $field['value']);
                $this->assertSame(config('branding.site_name'), $field['default']);
                $this->assertTrue($field['overridden']);
            });
    }

    public function test_saving_stores_the_values(): void
    {
        $this->save('branding', ['convention_name' => 'Testcon'])
            ->assertRedirect(route('manage.settings.group', 'branding'));

        $this->save('branding', ['primary_color' => '#ff8800']);
        $this->save('identity', ['login_headline' => 'Watch live']);

        $this->assertSame('Testcon', BrandingSetting::getValue('convention_name'));
        $this->assertSame('#ff8800', BrandingSetting::getValue('primary_color'));
        $this->assertSame('Watch live', app(BrandingService::class)->get('login_headline'));
    }

    /**
     * The tab icon falls back to the logo, and to nothing when neither is set - the
     * layout then serves the bundled mark. An installation that uploads only a logo
     * should not have to upload it twice to get it in the tab.
     */
    public function test_the_tab_icon_falls_back_to_the_logo(): void
    {
        Storage::fake('s3');

        $branding = app(BrandingService::class);

        $this->assertNull($branding->faviconUrl());

        $this->save('branding', ['logo_path' => 'branding/logo.png']);
        $this->assertStringContainsString('branding/logo.png', $branding->faviconUrl());

        $this->save('branding', ['favicon_path' => 'branding/tab-icon.png']);
        $this->assertStringContainsString('branding/tab-icon.png', $branding->faviconUrl());
    }

    public function test_the_tab_icon_is_offered_in_the_look_pane(): void
    {
        $field = collect(app(Settings::class)->group('branding')['fields'])
            ->firstWhere('key', 'favicon_path');

        $this->assertNotNull($field, 'The look pane must offer a tab icon upload.');
        $this->assertSame('branding_favicon', $field['purpose']);
        $this->assertArrayHasKey('branding_favicon', config('manage.uploads'));
    }

    public function test_saving_one_pane_leaves_the_others_alone(): void
    {
        BrandingSetting::setValue('control_key', 'a-long-enough-control-key');

        $this->save('branding', ['convention_name' => 'Testcon']);

        $this->assertSame('a-long-enough-control-key', ControlKey::current());
    }

    public function test_the_saved_values_reach_the_public_frontend_shape(): void
    {
        $this->save('identity', ['login_headline' => 'Livestream 2026']);
        $this->save('branding', ['convention_name' => 'Testcon']);

        $branding = app(BrandingService::class)->forFrontend();

        $this->assertSame('Livestream 2026', $branding['login']['headline']);
        $this->assertSame('Testcon', $branding['conventionName']);
    }

    public function test_required_copy_cannot_be_emptied(): void
    {
        $this->save('identity', ['login_headline' => ''])->assertSessionHasErrors('values.login_headline');
    }

    public function test_a_url_field_rejects_something_that_is_not_a_url(): void
    {
        $this->save('identity', ['identity_register_url' => 'not a url'])
            ->assertSessionHasErrors('values.identity_register_url');
    }

    public function test_footer_links_are_stored_as_an_ordered_list(): void
    {
        $this->save('branding', [
            'footer_links' => [
                ['label' => 'Code of Conduct', 'url' => 'https://example.test/coc'],
                ['label' => 'Support', 'url' => 'https://example.test/help'],
                ['label' => 'Privacy', 'url' => 'https://example.test/privacy'],
            ],
        ]);

        $this->assertSame([
            ['label' => 'Code of Conduct', 'url' => 'https://example.test/coc'],
            ['label' => 'Support', 'url' => 'https://example.test/help'],
            ['label' => 'Privacy', 'url' => 'https://example.test/privacy'],
        ], app(BrandingService::class)->footerLinks());
    }

    public function test_a_footer_link_needs_both_a_title_and_a_valid_url(): void
    {
        $this->save('branding', ['footer_links' => [['label' => 'Broken', 'url' => 'not a url']]])
            ->assertSessionHasErrors('values.footer_links.0.url');

        $this->save('branding', ['footer_links' => [['label' => '', 'url' => 'https://example.test']]])
            ->assertSessionHasErrors('values.footer_links.0.label');
    }

    public function test_no_footer_links_means_no_row_and_no_stored_value(): void
    {
        BrandingSetting::setValue('footer_links', json_encode([
            ['label' => 'Support', 'url' => 'https://example.test/help'],
        ]));

        $this->save('branding', ['footer_links' => []]);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'footer_links']);
        $this->assertSame([], app(BrandingService::class)->footerLinks());
        $this->assertSame([], app(BrandingService::class)->forFrontend()['links']);
    }

    public function test_the_accent_colour_must_be_a_hex_value(): void
    {
        $this->save('branding', ['primary_color' => 'rebeccapurple'])
            ->assertSessionHasErrors('values.primary_color');
    }

    public function test_an_unregistered_key_is_ignored_rather_than_stored(): void
    {
        $this->save('identity', ['rtmp_host' => 'evil.example.test']);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'rtmp_host']);
    }

    public function test_resetting_drops_every_saved_value(): void
    {
        /*
         * Reset is refused when it would leave no administrator a way in, so the
         * installation has to be one that still has one afterwards. A provider row is
         * what that takes now: rows are not settings rows, so a reset cannot take one
         * away. See App\Support\AuthModes::resetLockout().
         */
        $this->legacyProvider();

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

        $this->save('identity', ['login_headline' => config('branding.login_headline')]);

        // Not merely equal to the default: no row at all, so a later change to
        // the shipped default applies instead of being shadowed forever.
        $this->assertDatabaseMissing('branding_settings', ['key' => 'login_headline']);
        $this->assertSame(config('branding.login_headline'), BrandingSetting::getValue('login_headline'));
    }

    public function test_clearing_an_optional_field_whose_default_is_empty_stores_nothing(): void
    {
        // identity_register_url ships empty, so clearing it is a no-op that should
        // not leave a row behind either.
        BrandingSetting::setValue('identity_register_url', 'https://identity.test/register');

        $this->save('identity', ['identity_register_url' => '']);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'identity_register_url']);
    }

    public function test_clearing_optional_copy_puts_the_shipped_default_back(): void
    {
        // login_body ships with wording. Emptying the input is "I did not
        // choose one", not "I chose nothing": ConvertEmptyStringsToNull turns it
        // into null on the way in and a null row reads as unset, so the only
        // consistent outcome is the default.
        $this->assertNotSame('', (string) config('branding.login_body'));

        BrandingSetting::setValue('login_body', 'Custom wording');

        $this->save('identity', ['login_body' => '']);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'login_body']);
        $this->assertSame(config('branding.login_body'), BrandingSetting::getValue('login_body'));
    }

    public function test_a_secret_is_never_sent_to_the_browser(): void
    {
        BrandingSetting::setValue('pretalx_token', 'secret-token');

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'pretalx'))
            ->assertSuccessful()
            ->assertDontSee('secret-token')
            ->assertInertia(function (Assert $page) {
                $field = $this->field($page, 'pretalx_token');

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

        $this->save('pretalx', ['pretalx_token' => '']);

        $this->assertSame('secret-token', BrandingSetting::getValue('pretalx_token'));

        $this->save('pretalx', ['pretalx_token' => Settings::MASK_SECRET]);

        $this->assertSame('secret-token', BrandingSetting::getValue('pretalx_token'));
    }

    public function test_a_secret_is_replaced_when_one_is_typed_and_cleared_on_request(): void
    {
        BrandingSetting::setValue('pretalx_token', 'secret-token');

        $this->save('pretalx', ['pretalx_token' => 'new-token']);

        $this->assertSame('new-token', BrandingSetting::getValue('pretalx_token'));

        $this->save('pretalx', ['pretalx_token' => Settings::CLEAR_SECRET]);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'pretalx_token']);
    }

    /**
     * The control key is the other kind of secret: one this installation hands out, so
     * the page has to be able to show it. It is guarded by the admin-only settings page
     * rather than by never leaving the server.
     */
    public function test_the_control_key_is_readable_on_the_page_and_saved(): void
    {
        $this->save('playback', ['control_key' => 'a-long-enough-control-key']);

        $this->assertSame('a-long-enough-control-key', BrandingSetting::getValue('control_key'));
        $this->assertSame('a-long-enough-control-key', ControlKey::current());

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'playback'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $field = $this->field($page, 'control_key');

                $this->assertSame('a-long-enough-control-key', $field['value']);
                $this->assertTrue($field['hasValue']);
            });
    }

    /**
     * The card hands over the module as well as the key, and a note saves nothing: it
     * is not a field, so it never reaches the values a save posts.
     *
     * Per card rather than per pane, which is what lets one pane carry the Companion
     * download and the archiver builds at the same time.
     */
    public function test_the_control_pane_links_to_the_companion_module(): void
    {
        config(['stream.companion_module_url' => 'https://example.test/stream-control.tgz']);

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'playback'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $card = $this->card($page, 'surfaces');

                $this->assertSame('https://example.test/stream-control.tgz', $card['note']['url']);
            });
    }

    public function test_a_note_with_nothing_behind_it_is_not_shown(): void
    {
        config(['stream.companion_module_url' => '']);

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'playback'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $this->assertNull($this->card($page, 'surfaces')['note']);
            });
    }

    public function test_clearing_the_control_key_closes_the_control_api(): void
    {
        // The table is the only source, so an emptied field is not a fallback to
        // anything: it switches the control API off.
        $this->save('playback', ['control_key' => 'saved-control-key-value']);

        $this->assertSame('saved-control-key-value', ControlKey::current());

        $this->save('playback', ['control_key' => '']);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'control_key']);
        $this->assertSame('', ControlKey::current());
    }

    public function test_a_short_control_key_is_rejected(): void
    {
        $this->save('playback', ['control_key' => 'short'])->assertSessionHasErrors('values.control_key');

        $this->assertDatabaseMissing('branding_settings', ['key' => 'control_key']);
    }

    public function test_turning_the_source_credit_off_stores_it_and_hides_it_from_the_frontend(): void
    {
        $this->assertTrue(app(BrandingService::class)->showSourceLink());
        $this->assertNotNull(app(BrandingService::class)->forFrontend()['source']);

        $this->save('branding', ['show_source_link' => false]);

        // Stored as a string, because that is what the settings table holds.
        $this->assertSame('0', BrandingSetting::getValue('show_source_link'));
        $this->assertFalse(app(BrandingService::class)->showSourceLink());
        $this->assertNull(app(BrandingService::class)->forFrontend()['source']);
    }

    public function test_turning_the_source_credit_back_on_hands_the_key_back_to_the_default(): void
    {
        BrandingSetting::setValue('show_source_link', '0');

        $this->save('branding', ['show_source_link' => true]);

        $this->assertDatabaseMissing('branding_settings', ['key' => 'show_source_link']);
        $this->assertTrue(app(BrandingService::class)->showSourceLink());
    }

    /**
     * A password is write-only, so nothing the page receives may carry the stored value.
     */
    private function assertSecretIsNotOnThePage(string $group, string $key, string $value): void
    {
        BrandingSetting::setValue($key, $value, null, true);

        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', $group))
            ->assertSuccessful()
            ->assertDontSee($value)
            ->assertInertia(function (Assert $page) use ($key) {
                $field = $this->field($page, $key);

                $this->assertSame(Settings::MASK_SECRET, $field['value']);
                $this->assertTrue($field['hasValue']);
            });

        // And it is not sitting in the table in the clear either.
        $stored = DB::table('branding_settings')->where('key', $key)->first();

        $this->assertTrue((bool) $stored->encrypted);
        $this->assertStringNotContainsString($value, $stored->value);
    }

    public function test_a_saved_chat_limit_reaches_its_config_path_as_an_integer(): void
    {
        $this->save('chat', [
            'chat_max_tries' => '12',
            'chat_rate_decay' => '45',
            'chat_slow_mode_seconds' => '5',
            'chat_max_message_length' => '240',
        ]);

        RuntimeConfig::apply();

        $this->assertSame(12, config('chat.default.maxTries'));
        $this->assertSame(45, config('chat.default.rateDecay'));
        $this->assertSame(5, config('chat.default.slowModeSeconds'));
        $this->assertSame(240, config('chat.default.maxMessageLength'));
    }

    public function test_allowed_domains_are_written_one_per_line_and_read_as_a_list(): void
    {
        $this->save('chat', ['chat_allowed_domains' => "example.org\nexample.test\n"]);

        RuntimeConfig::apply();

        $this->assertSame(
            ['example.org', 'example.test'],
            app(ChatMessageSanitizer::class)->getAllowedDomains(),
        );
    }

    public function test_a_chat_limit_outside_its_range_is_rejected(): void
    {
        $this->save('chat', ['chat_max_message_length' => '0'])
            ->assertSessionHasErrors('values.chat_max_message_length');
    }

    public function test_a_saved_archive_setting_reaches_the_disk_and_the_stream_config(): void
    {
        $this->save('storage', [
            'archive_s3_bucket' => 'recordings-2026',
            'archive_s3_region' => 'eu-central-1',
            'archive_disk' => 'dvr',
            'archive_url_ttl' => '3600',
            'archive_source_in_master' => true,
        ]);

        RuntimeConfig::apply();

        $this->assertSame('recordings-2026', config('filesystems.disks.dvr.bucket'));
        $this->assertSame('eu-central-1', config('filesystems.disks.dvr.region'));
        // The surrounding disk is merged into, not replaced.
        $this->assertSame('s3', config('filesystems.disks.dvr.driver'));
        $this->assertSame('dvr', config('stream.archive_disk'));
        $this->assertSame(3600, config('stream.archive_url_ttl'));
        $this->assertTrue(config('stream.archive_source_in_master'));
    }

    public function test_the_archive_credentials_are_never_sent_to_the_browser(): void
    {
        $this->assertSecretIsNotOnThePage('storage', 'archive_s3_secret', 'dvr-secret-access-key');
    }

    public function test_a_saved_streaming_setting_reaches_its_config_path(): void
    {
        $this->save('streaming', [
            'image_ffmpeg_hls' => 'ghcr.io/example/ffmpeg-hls:v2',
            'server_metrics_retention_days' => '14',
        ]);

        RuntimeConfig::apply();

        $this->assertSame('ghcr.io/example/ffmpeg-hls:v2', config('stream.images.ffmpeg_hls'));
        $this->assertSame(14, config('stream.server.metrics_retention_days'));
    }

    /**
     * The bucket card only ever writes filesystems.disks.dvr.*, so pointing the archive
     * at the general s3 disk has to leave it visibly inert rather than editing a disk
     * nothing reads. The disk control comes first for the same reason: an operator has
     * to reach it before the card it disables.
     */
    public function test_the_archive_bucket_card_goes_inert_when_the_disk_is_the_general_one(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'storage'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $cards = collect($page->toArray()['props']['group']['cards'])->pluck('key')->all();

                $this->assertSame(['disk', 'bucket', 'playback'], $cards);

                $rule = $this->card($page, 'bucket')['inertWhen'];

                $this->assertSame('archive_disk', $rule['field']);
                $this->assertSame(['s3'], $rule['is']);
                $this->assertNotNull($rule['description']);

                // Every other card is unconditional.
                $this->assertNull($this->card($page, 'disk')['inertWhen']);
                $this->assertNull($this->card($page, 'playback')['inertWhen']);
            });
    }

    /**
     * Inert is layout, like visible_when a level down: the credentials stay editable
     * through the API and a save still carries them, so switching the disk back does
     * not hand back an emptied bucket.
     */
    public function test_the_archive_bucket_still_saves_while_the_disk_is_the_general_one(): void
    {
        $this->save('storage', [
            'archive_disk' => 's3',
            'archive_s3_bucket' => 'ef-archive',
        ]);

        RuntimeConfig::apply();

        $this->assertSame('s3', config('stream.archive_disk'));
        $this->assertSame('ef-archive', config('filesystems.disks.dvr.bucket'));
    }

    public function test_a_saved_playback_setting_reaches_its_config_path(): void
    {
        $this->save('playback', [
            'hls_token_ttl' => '600',
            'hls_token_leeway' => '30',
            'system_streamkey' => 'a-system-streamkey-value',
        ]);

        RuntimeConfig::apply();

        $this->assertSame(600, config('stream.token.ttl'));
        $this->assertSame(30, config('stream.token.leeway'));
        $this->assertSame('a-system-streamkey-value', config('stream.system_streamkey'));
    }

    /**
     * A secret is readable on the page by design - this installation hands it out - but
     * it still has to be encrypted at rest, and the recording API has to accept it.
     */
    public function test_the_recording_api_key_is_stored_encrypted_and_reaches_the_api(): void
    {
        $this->save('playback', ['recording_api_key' => 'a-recording-api-key']);

        $stored = DB::table('branding_settings')->where('key', 'recording_api_key')->first();

        $this->assertTrue((bool) $stored->encrypted);
        $this->assertStringNotContainsString('a-recording-api-key', $stored->value);

        RuntimeConfig::apply();

        $this->assertSame('a-recording-api-key', config('app.recording_api_key'));
    }

    public function test_the_token_secrets_are_never_sent_to_the_browser(): void
    {
        // Provisioning puts these on the edges; nobody copies them off the page, so the
        // page never receives them.
        $this->assertSecretIsNotOnThePage('playback', 'hls_viewer_secret', str_repeat('v', 64));
        $this->assertSecretIsNotOnThePage('playback', 'hls_embed_secret', str_repeat('e', 64));
    }

    public function test_a_short_playback_secret_is_rejected(): void
    {
        $this->save('playback', ['hls_viewer_secret' => 'too-short'])
            ->assertSessionHasErrors('values.hls_viewer_secret');

        $this->assertDatabaseMissing('branding_settings', ['key' => 'hls_viewer_secret']);
    }

    public function test_every_new_pane_renders(): void
    {
        foreach (['chat', 'storage', 'streaming', 'infrastructure', 'playback'] as $group) {
            $this->actingAs($this->admin)
                ->get(route('manage.settings.group', $group))
                ->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page->where('group.key', $group));
        }
    }

    public function test_only_administrators_can_read_or_change_the_settings(): void
    {
        // The moderator holds the manage gate but not admin.access.
        $this->actingAs($this->moderator)->get(route('manage.settings'))->assertForbidden();
        $this->actingAs($this->moderator)->get(route('manage.settings.group', 'identity'))->assertForbidden();

        $this->actingAs($this->moderator)
            ->put(route('manage.settings.update', 'identity'), ['values' => $this->values('identity')])
            ->assertForbidden();

        $this->actingAs($this->moderator)->post(route('manage.settings.reset'))->assertForbidden();
    }
}
