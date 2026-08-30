<?php

namespace Tests\Feature\Manage;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Support\Manage\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The provider rows in /manage. A settings area whose contents are rows, and behind
 * admin.access rather than access-manage because a row here is a credential.
 */
class AuthProvidersTest extends TestCase
{
    use ConfiguresAuthProviders;
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->createManageUsers();

        $this->legacyProvider();
        $this->connect($this->admin, AuthProvider::legacy());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'oidc',
            'key' => 'second',
            'label' => 'Second',
            'client_id' => 'streaming',
            'client_secret' => 'a-client-secret',
            'issuer_url' => 'https://second.example.org',
            'enabled' => true,
            'order' => 1,
            'grants_baseline' => true,
        ], $overrides);
    }

    private function store(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('manage.providers.store'), $this->payload($overrides));
    }

    public function test_the_list_renders_inside_the_settings_menu(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.providers.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Providers/Index')
                ->has('table.rows', 1)
                ->has('navigation'));
    }

    public function test_a_provider_is_added_and_edited(): void
    {
        $this->store()->assertRedirect();

        $provider = AuthProvider::where('key', 'second')->firstOrFail();

        $this->assertSame('a-client-secret', $provider->client_secret);
        $this->assertSame(route('auth.provider.callback', 'second'), $provider->redirectUrl());

        $this->actingAs($this->admin)
            ->put(route('manage.providers.update', $provider), $this->payload([
                'label' => 'Renamed',
                'client_secret' => Settings::MASK_SECRET,
            ]))
            ->assertRedirect();

        $provider->refresh();

        $this->assertSame('Renamed', $provider->label);
        // The mask means "leave it": a form that never sees the secret must not be able
        // to blank it by being submitted.
        $this->assertSame('a-client-secret', $provider->client_secret);
    }

    public function test_the_secret_is_never_sent_to_the_browser(): void
    {
        $this->store();

        $this->actingAs($this->admin)
            ->get(route('manage.providers.edit', AuthProvider::where('key', 'second')->first()))
            ->assertSuccessful()
            ->assertDontSee('a-client-secret');
    }

    public function test_the_key_has_to_be_a_url_segment_and_unique(): void
    {
        $this->store(['key' => 'Not A Key'])->assertSessionHasErrors('key');

        $this->store()->assertRedirect();
        $this->store()->assertSessionHasErrors('key');
    }

    public function test_the_role_map_names_a_role_by_id(): void
    {
        $role = Role::create(['name' => 'Staff', 'slug' => 'staff']);

        $this->store([
            'role_map' => [
                ['claim' => 'groups', 'match' => 'exact', 'value' => 'GROUP-STAFF', 'role_id' => $role->id],
            ],
        ])->assertRedirect();

        // assertEquals, not assertSame: MySQL's JSON type stores object keys in its own
        // order, so an identical rule read back off the column compares unequal by key
        // order alone. These four happen to be stored in the order they are written; a
        // rename would silently change that.
        $this->assertEquals(
            [['claim' => 'groups', 'match' => 'exact', 'value' => 'GROUP-STAFF', 'role_id' => $role->id]],
            AuthProvider::where('key', 'second')->first()->role_map,
        );

        $this->store([
            'key' => 'third',
            'role_map' => [['claim' => 'groups', 'match' => 'exact', 'value' => 'x', 'role_id' => 9999]],
        ])->assertSessionHasErrors('role_map.0.role_id');
    }

    public function test_a_moderator_cannot_read_or_change_the_providers(): void
    {
        $this->actingAs($this->moderator)->get(route('manage.providers.index'))->assertForbidden();
        $this->actingAs($this->moderator)->post(route('manage.providers.store'), $this->payload())->assertForbidden();
    }

    /**
     * The sign-in pane points at this page rather than listing the rows itself, so
     * there is one place a provider is read and edited.
     */
    public function test_the_sign_in_pane_points_at_the_provider_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.settings.group', 'identity'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('providers')
                ->where('providersUrl', route('manage.providers.index')));
    }

    /**
     * A row nobody signs in through can go; one accounts hold identities on cannot,
     * because the foreign key cascades and would take them all with it.
     */
    public function test_deleting_a_provider_accounts_use_is_refused(): void
    {
        $this->store()->assertRedirect();
        $provider = AuthProvider::where('key', 'second')->firstOrFail();

        User::factory()->create()->identities()->create([
            'auth_provider_id' => $provider->id,
            'subject' => 'somebody',
        ]);

        $this->actingAs($this->admin)
            ->from(route('manage.providers.edit', $provider))
            ->delete(route('manage.providers.destroy', $provider))
            ->assertRedirect(route('manage.providers.edit', $provider));

        $this->assertDatabaseHas('auth_providers', ['id' => $provider->id]);
        $this->assertDatabaseCount('user_identities', 2);
    }
}
