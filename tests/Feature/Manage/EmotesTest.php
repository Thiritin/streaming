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
        Storage::disk('s3')->put('emotes/wave.png', 'png');
        Storage::disk('s3')->put('emotes/other.png', 'png');
        Storage::disk('s3')->put('recordings/master.mp4', 'not for chat');

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

    public function test_the_s3_key_must_sit_under_the_emote_prefix(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.emotes.store'), [
                'name' => 'leak',
                's3_key' => 'recordings/master.mp4',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasErrors('s3_key');

        $this->assertDatabaseMissing('emotes', ['name' => 'leak']);
    }

    public function test_the_s3_key_cannot_climb_out_of_the_prefix(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.emotes.store'), [
                'name' => 'leak',
                's3_key' => 'emotes/../recordings/master.mp4',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasErrors('s3_key');

        $this->assertDatabaseMissing('emotes', ['name' => 'leak']);
    }

    public function test_the_s3_key_must_be_an_object_that_was_uploaded(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.emotes.store'), [
                'name' => 'ghost',
                's3_key' => 'emotes/never-uploaded.png',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasErrors('s3_key');

        $this->assertDatabaseMissing('emotes', ['name' => 'ghost']);
    }

    public function test_an_uploaded_key_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.emotes.store'), [
                'name' => 'clap',
                's3_key' => 'emotes/other.png',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('emotes', ['name' => 'clap', 's3_key' => 'emotes/other.png']);
    }

    public function test_an_edit_cannot_repoint_an_emote_at_another_object(): void
    {
        $emote = $this->emote();

        $this->actingAs($this->admin)
            ->put(route('manage.emotes.update', $emote), [
                'name' => 'wave',
                's3_key' => 'recordings/master.mp4',
                'is_global' => true,
                'is_approved' => true,
            ])
            ->assertSessionHasErrors('s3_key');

        $this->assertSame('emotes/wave.png', $emote->fresh()->s3_key);
    }

    public function test_deleting_an_emote_never_removes_an_object_outside_the_prefix(): void
    {
        $emote = $this->emote(['name' => 'stray', 's3_key' => 'recordings/master.mp4']);

        $this->actingAs($this->admin)
            ->delete(route('manage.emotes.destroy', $emote))
            ->assertRedirect();

        $this->assertDatabaseMissing('emotes', ['id' => $emote->id]);
        Storage::disk('s3')->assertExists('recordings/master.mp4');
    }

    public function test_a_key_outside_the_prefix_is_never_signed(): void
    {
        $emote = $this->emote(['name' => 'stray', 's3_key' => 'recordings/master.mp4']);

        $this->assertNull($emote->url);
    }

    public function test_a_user_without_chat_moderation_cannot_approve(): void
    {
        $emote = $this->emote();

        $this->actingAs($this->viewer)
            ->post(route('manage.emotes.approve', $emote))
            ->assertForbidden();
    }
}
