<?php

namespace Tests\Feature\Manage;

use App\Models\Emote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class EmotesTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Emote::url signs against the s3 disk, which has no bucket in the test env.
        Storage::fake('s3');

        $this->createManageUsers();
    }

    private function emote(array $overrides = []): Emote
    {
        return Emote::create(array_merge([
            'name' => 'wave',
            's3_key' => 'emotes/wave.png',
            'is_global' => true,
            'is_approved' => false,
            'uploaded_by_user_id' => $this->viewer->id,
        ], $overrides));
    }

    public function test_the_list_loads(): void
    {
        $this->emote();

        $this->actingAs($this->admin)
            ->get(route('manage.emotes.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Emotes/Index')
                ->has('table.rows', 1));
    }

    public function test_approving_records_the_approver(): void
    {
        $emote = $this->emote();

        $this->actingAs($this->admin)
            ->post(route('manage.emotes.approve', $emote))
            ->assertRedirect();

        $emote->refresh();

        $this->assertTrue($emote->is_approved);
        $this->assertSame($this->admin->id, $emote->approved_by_user_id);
        $this->assertNotNull($emote->approved_at);
    }

    public function test_the_name_must_be_lowercase_and_unique(): void
    {
        $this->emote();

        $this->actingAs($this->admin)
            ->post(route('manage.emotes.store'), [
                'name' => 'Wave',
                's3_key' => 'emotes/other.png',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin)
            ->post(route('manage.emotes.store'), [
                'name' => 'wave',
                's3_key' => 'emotes/other.png',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_bulk_approve_skips_already_approved_emotes(): void
    {
        $pending = $this->emote();
        $approved = $this->emote(['name' => 'clap', 'is_approved' => true]);

        $this->actingAs($this->admin)
            ->post(route('manage.emotes.bulk.approve'), ['ids' => [$pending->id, $approved->id]])
            ->assertRedirect();

        $this->assertTrue($pending->fresh()->is_approved);
        $this->assertSame($this->admin->id, $pending->fresh()->approved_by_user_id);
        // Untouched: it was already approved, so no approver is rewritten.
        $this->assertNull($approved->fresh()->approved_by_user_id);
    }

    public function test_a_user_without_chat_moderation_cannot_approve(): void
    {
        $emote = $this->emote();

        $this->actingAs($this->viewer)
            ->post(route('manage.emotes.approve', $emote))
            ->assertForbidden();
    }
}
