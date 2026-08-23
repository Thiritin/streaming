<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Models\RecordingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A viewer's position is measured against what actually played.
 *
 * `recordings.duration` is metadata - read off a playlist once, typed in for an
 * import, stale after a re-cut - while the player knows the length of the media in
 * front of it. Storing the record's number here is what put a tile's progress bar
 * and the bar under the player on different scales.
 */
class RecordingProgressDurationTest extends TestCase
{
    use RefreshDatabase;

    private function recording(): Recording
    {
        return Recording::create([
            'title' => 'Closing Ceremony',
            'slug' => 'closing-ceremony',
            'date' => now(),
            // Wrong on purpose: the media below is ten minutes long.
            'duration' => 3600,
            'm3u8_url' => 'https://cdn.example.test/closing/index.m3u8',
            'is_published' => true,
        ]);
    }

    public function test_the_measured_length_is_what_the_position_is_stored_against(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('recordings.progress', $recording), ['position' => 600, 'duration' => 635])
            ->assertNoContent();

        $row = RecordingProgress::first();

        $this->assertSame(600, $row->position);
        $this->assertSame(635, $row->duration);
        // 600 of 635 is not yet the 97% that counts as watched.
        $this->assertFalse($row->completed);
        // Against the record's 3600 this would have read as a sixth watched.
        $this->assertEqualsWithDelta(0.945, $row->fraction(), 0.001);
    }

    public function test_a_position_is_no_longer_clipped_to_a_wrong_record(): void
    {
        $recording = $this->recording();
        $recording->update(['duration' => 120]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('recordings.progress', $recording), ['position' => 400, 'duration' => 635])
            ->assertNoContent();

        $this->assertSame(400, RecordingProgress::first()->position);
    }

    public function test_an_implausible_client_length_falls_back_to_the_record(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('recordings.progress', $recording), ['position' => 600, 'duration' => 500000])
            ->assertNoContent();

        $this->assertSame(3600, RecordingProgress::first()->duration);
    }

    public function test_the_row_carries_when_it_was_written(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('recordings.progress', $recording), ['position' => 300, 'duration' => 635]);

        $this->actingAs($user)
            ->get(route('recordings.index'))
            ->assertSuccessful();

        $this->assertNotNull(RecordingProgress::first()->updated_at);
    }
}
