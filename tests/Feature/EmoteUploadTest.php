<?php

namespace Tests\Feature;

use App\Models\Emote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmoteUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_oversized_upload_is_squared_to_the_emote_size(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('emotes.store'), [
                'name' => 'wave',
                'image' => UploadedFile::fake()->image('wave.png', 200, 120),
            ])
            ->assertRedirect(route('emotes.index'));

        $emote = Emote::where('name', 'wave')->firstOrFail();

        Storage::disk('s3')->assertExists($emote->s3_key);

        $stored = getimagesizefromstring(Storage::disk('s3')->get($emote->s3_key));

        $this->assertSame([64, 64], [$stored[0], $stored[1]]);
        $this->assertFalse($emote->is_approved);
    }

    public function test_an_image_already_at_the_emote_size_is_stored_untouched(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('grin.png', 64, 64);
        $original = file_get_contents($file->getRealPath());

        $this->actingAs($user)
            ->post(route('emotes.store'), ['name' => 'grin', 'image' => $file])
            ->assertRedirect(route('emotes.index'));

        $emote = Emote::where('name', 'grin')->firstOrFail();

        $this->assertSame($original, Storage::disk('s3')->get($emote->s3_key));
    }
}
