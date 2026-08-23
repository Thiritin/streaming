<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Support\SkipSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Skip points are an offer and never an edit.
 *
 * Nothing here touches the media or the playlist: the player shows a button while
 * the playhead is inside a marked range, and only a press moves it. What is worth
 * pinning is the shape of the stored set, because the player walks it without
 * checking anything - it assumes the ranges are sorted, real and non-overlapping.
 */
class RecordingSkipsTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    private function recording(array $overrides = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'Dance Competition',
            'slug' => 'dance-competition',
            'date' => now(),
            'duration' => 3600,
            'm3u8_url' => 'https://cdn.example.test/dance/index.m3u8',
            'is_published' => true,
        ], $overrides));
    }

    public function test_overlapping_ranges_are_merged(): void
    {
        $segments = SkipSegments::normalise([
            ['start' => 600, 'end' => 900, 'label' => 'Intermission'],
            ['start' => 120, 'end' => 200, 'label' => 'Wait'],
            ['start' => 850, 'end' => 1000, 'label' => 'Still waiting'],
        ]);

        $this->assertSame([
            ['start' => 120, 'end' => 200, 'label' => 'Wait'],
            ['start' => 600, 'end' => 1000, 'label' => 'Intermission'],
        ], $segments);
    }

    public function test_a_range_that_ends_before_it_starts_is_dropped(): void
    {
        $this->assertSame([], SkipSegments::normalise([
            ['start' => 300, 'end' => 300],
            ['start' => 400, 'end' => 100],
        ]));
    }

    public function test_a_range_is_clamped_to_the_length_of_the_recording(): void
    {
        $segments = SkipSegments::normalise([['start' => 3500, 'end' => 9999]], 3600);

        $this->assertSame([['start' => 3500, 'end' => 3600, 'label' => null]], $segments);
    }

    public function test_the_player_page_carries_the_skips(): void
    {
        $recording = $this->recording([
            'skip_segments' => [['start' => 120, 'end' => 300, 'label' => 'Intermission']],
        ]);

        $this->actingAs($this->viewer)
            ->get(route('recordings.show', $recording->id))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RecordingPlayer')
                ->where('skips.0.start', 120)
                ->where('skips.0.label', 'Intermission'));
    }

    /**
     * Marking them is an operator's job and lives in /manage, so the form is the
     * only way in and the recording policy is the only thing that opens it.
     */
    public function test_marking_them_needs_stream_manage(): void
    {
        $recording = $this->recording();

        $this->actingAs($this->viewer)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'skip_segments' => [['start' => 10, 'end' => 20, 'label' => null]],
            ]))
            ->assertForbidden();

        $this->assertNull($recording->fresh()->skip_segments);
    }

    public function test_an_operator_marks_them_on_the_recording_form(): void
    {
        $recording = $this->recording();

        $this->actingAs($this->admin)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'skip_segments' => [
                    ['start' => 400, 'end' => 500, 'label' => 'Changeover'],
                    ['start' => 60, 'end' => 90, 'label' => null],
                ],
            ]))
            ->assertRedirect();

        $this->assertSame([
            ['start' => 60, 'end' => 90, 'label' => null],
            ['start' => 400, 'end' => 500, 'label' => 'Changeover'],
        ], $recording->fresh()->skips());
    }

    public function test_saving_an_empty_set_clears_them(): void
    {
        $recording = $this->recording([
            'skip_segments' => [['start' => 60, 'end' => 90, 'label' => null]],
        ]);

        $this->actingAs($this->admin)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'skip_segments' => [],
            ]))
            ->assertRedirect();

        $this->assertSame([], $recording->fresh()->skips());
    }

    public function test_the_form_carries_the_skips(): void
    {
        $recording = $this->recording([
            'skip_segments' => [['start' => 60, 'end' => 90, 'label' => 'Wait']],
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.edit', $recording))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Recordings/Form')
                ->where('recording.skip_segments.0.label', 'Wait'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Recording $recording, array $overrides = []): array
    {
        return array_merge([
            'show_id' => '',
            'category_id' => '',
            'title' => $recording->title,
            'slug' => $recording->slug,
            'description' => '',
            'date' => $recording->date->format('Y-m-d\TH:i'),
            'duration' => $recording->duration,
            'm3u8_url' => $recording->m3u8_url,
            'thumbnail_path' => '',
            'is_published' => true,
            'required_roles' => [],
        ], $overrides);
    }
}
