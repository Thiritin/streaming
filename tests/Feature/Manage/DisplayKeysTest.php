<?php

namespace Tests\Feature\Manage;

use App\Models\EmbedKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class DisplayKeysTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    public function test_signing_out_screens_leaves_the_key_usable(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');

        $this->actingAs($this->admin)
            ->post(route('manage.embed-keys.sign-out', $key))
            ->assertRedirect(route('manage.embed-keys.index'));

        $this->assertNotNull($key->fresh()->signed_out_at);
        $this->assertNotNull(EmbedKey::findByKey($key->key));
    }

    public function test_signing_out_screens_needs_the_manage_permission(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');

        $this->actingAs($this->viewer)
            ->post(route('manage.embed-keys.sign-out', $key))
            ->assertForbidden();

        $this->assertNull($key->fresh()->signed_out_at);
    }
}
