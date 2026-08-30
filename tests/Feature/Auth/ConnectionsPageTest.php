<?php

namespace Tests\Feature\Auth;

use App\Models\AuthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\TestCase;

/**
 * The ways into a viewer's own account: what /settings/connections offers, and the one
 * thing it refuses.
 */
class ConnectionsPageTest extends TestCase
{
    use ConfiguresAuthProviders;
    use RefreshDatabase;

    public function test_the_page_lists_every_provider_and_says_which_are_connected(): void
    {
        $connected = AuthProvider::factory()->create(['label' => 'Convention identity']);
        $offered = AuthProvider::factory()->create(['label' => 'Elsewhere']);

        $user = User::factory()->create(['sub' => null]);
        $this->connect($user, $connected);

        $this->actingAs($user)
            ->get(route('settings.edit', 'connections'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) use ($connected, $offered) {
                $rows = collect($page->toArray()['props']['connections']['providers'])->keyBy('label');

                $this->assertTrue($rows[$connected->label]['connected']);
                $this->assertFalse($rows[$offered->label]['connected']);
                $this->assertNotNull($rows[$offered->label]['connectUrl']);
            });
    }

    /**
     * With nothing to connect and nothing connected there is no page, rather than an
     * empty one in the menu.
     */
    public function test_the_page_is_not_offered_without_providers(): void
    {
        $this->actingAs(User::factory()->local()->create())
            ->get(route('settings.edit', 'connections'))
            ->assertNotFound();
    }

    /**
     * A provider an administrator has switched off still appears while the account
     * holds an identity on it, or there would be no way to take it off.
     */
    public function test_a_switched_off_provider_stays_listed_while_it_is_connected(): void
    {
        $provider = AuthProvider::factory()->disabled()->create(['label' => 'Retired']);

        $user = User::factory()->local()->create();
        $this->connect($user, $provider);

        $this->actingAs($user)
            ->get(route('settings.edit', 'connections'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $row = collect($page->toArray()['props']['connections']['providers'])->firstWhere('label', 'Retired');

                $this->assertTrue($row['connected']);
                // Nothing to connect to any more, but it can still be let go of.
                $this->assertNull($row['connectUrl']);
                $this->assertTrue($row['canDisconnect']);
            });
    }

    public function test_a_provider_can_be_disconnected_while_another_way_in_is_left(): void
    {
        $provider = AuthProvider::factory()->create();

        // A password as well, so this is not the last way in.
        $user = User::factory()->local()->create();
        $this->connect($user, $provider);

        $this->actingAs($user)
            ->from(route('settings.edit', 'connections'))
            ->delete(route('settings.connections.destroy', $provider->id))
            ->assertRedirect(route('settings.edit', 'connections'));

        $this->assertDatabaseMissing('user_identities', [
            'user_id' => $user->id,
            'auth_provider_id' => $provider->id,
        ]);
    }

    /**
     * The refusal the whole page is built around: disconnecting the only way in leaves
     * an account nothing can open, and nobody can ask for it back from here.
     */
    public function test_the_last_way_in_cannot_be_disconnected(): void
    {
        $provider = AuthProvider::factory()->create();

        $user = User::factory()->create(['sub' => null, 'password' => null]);
        $this->connect($user, $provider);

        $this->actingAs($user)
            ->from(route('settings.edit', 'connections'))
            ->delete(route('settings.connections.destroy', $provider->id))
            ->assertRedirect(route('settings.edit', 'connections'))
            ->assertSessionHasErrors('connection');

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'auth_provider_id' => $provider->id,
        ]);

        $this->actingAs($user)
            ->get(route('settings.edit', 'connections'))
            ->assertInertia(function (Assert $page) {
                $row = collect($page->toArray()['props']['connections']['providers'])->first();

                $this->assertFalse($row['canDisconnect']);
            });
    }

    /**
     * Setting a password is what makes the refusal above escapable without an
     * administrator: it is a second way in, so the identity can then go.
     */
    public function test_setting_a_password_is_a_second_way_in(): void
    {
        config(['auth.modes.local' => true]);

        $provider = AuthProvider::factory()->create();

        $user = User::factory()->create(['sub' => null, 'password' => null]);
        $this->connect($user, $provider);

        $this->actingAs($user)
            ->from(route('settings.edit', 'account'))
            ->put(route('settings.password.update'), [
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->password);
        $this->assertSame(2, $user->fresh()->signInMethodCount());

        $this->actingAs($user->fresh())
            ->from(route('settings.edit', 'connections'))
            ->delete(route('settings.connections.destroy', $provider->id))
            ->assertSessionHasNoErrors();
    }
}
