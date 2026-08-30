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

    public function test_trimming_the_head_moves_every_skip_with_it(): void
    {
        $start = now()->subHours(3)->startOfMinute();

        $recording = $this->recording([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'duration' => 3600,
            'skip_segments' => [
                ['start' => 600, 'end' => 900, 'label' => 'Intermission'],
                ['start' => 1800, 'end' => 1860, 'label' => 'Wait'],
            ],
        ]);

        // The in-point moves 60 seconds later, so everything is 60 seconds earlier.
        $this->actingAs($this->admin)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'starts_at' => $start->copy()->addMinute()->format('Y-m-d\TH:i:s'),
                'ends_at' => $start->copy()->addHour()->format('Y-m-d\TH:i:s'),
                'cut_fingerprint' => $recording->cutFingerprint(),
                'skip_segments' => [
                    ['start' => 600, 'end' => 900, 'label' => 'Intermission'],
                    ['start' => 1800, 'end' => 1860, 'label' => 'Wait'],
                ],
            ]));

        $this->assertSame(
            [
                ['start' => 540, 'end' => 840, 'label' => 'Intermission'],
                ['start' => 1740, 'end' => 1800, 'label' => 'Wait'],
            ],
            $recording->fresh()->skips(),
        );
    }

    public function test_a_skip_trimmed_off_the_front_is_dropped_rather_than_pinned(): void
    {
        $start = now()->subHours(3)->startOfMinute();

        $recording = $this->recording([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'duration' => 3600,
            'skip_segments' => [['start' => 30, 'end' => 90, 'label' => 'Doors']],
        ]);

        // Two minutes off the head: the marked stretch is no longer in the cut.
        $this->actingAs($this->admin)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'starts_at' => $start->copy()->addMinutes(2)->format('Y-m-d\TH:i:s'),
                'ends_at' => $start->copy()->addHour()->format('Y-m-d\TH:i:s'),
                'cut_fingerprint' => $recording->cutFingerprint(),
                'skip_segments' => [['start' => 30, 'end' => 90, 'label' => 'Doors']],
            ]));

        $this->assertSame([], $recording->fresh()->skip_segments);
    }

    public function test_a_save_against_a_cut_somebody_else_changed_is_refused(): void
    {
        $start = now()->subHours(3)->startOfMinute();

        $recording = $this->recording([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'duration' => 3600,
            'skip_segments' => [],
        ]);

        // What the form loaded with, before somebody else re-cut it underneath.
        $stale = $recording->cutFingerprint();

        $recording->forceFill([
            'starts_at' => $start->copy()->addMinutes(5),
            'ends_at' => $start->copy()->addHour(),
            'duration' => 3300,
        ])->save();

        $this->actingAs($this->admin)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'starts_at' => $start->format('Y-m-d\TH:i:s'),
                'ends_at' => $start->copy()->addHour()->format('Y-m-d\TH:i:s'),
                'cut_fingerprint' => $stale,
                'skip_segments' => [['start' => 600, 'end' => 900, 'label' => 'Marked against the old cut']],
            ]));

        // Nothing written: not the skips, and not the markers they were marked against.
        $fresh = $recording->fresh();
        $this->assertSame([], $fresh->skip_segments);
        $this->assertSame(3300, (int) $fresh->duration);
    }

    public function test_a_save_carrying_the_current_cut_goes_through(): void
    {
        $start = now()->subHours(3)->startOfMinute();

        $recording = $this->recording([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'duration' => 3600,
        ]);

        $this->actingAs($this->admin)
            ->put(route('manage.recordings.update', $recording), $this->payload($recording, [
                'starts_at' => $start->format('Y-m-d\TH:i:s'),
                'ends_at' => $start->copy()->addHour()->format('Y-m-d\TH:i:s'),
                'cut_fingerprint' => $recording->cutFingerprint(),
                'skip_segments' => [['start' => 600, 'end' => 900, 'label' => 'Intermission']],
            ]));

        $this->assertSame(
            [['start' => 600, 'end' => 900, 'label' => 'Intermission']],
            $recording->fresh()->skips(),
        );
    }

    public function test_the_watch_page_carries_the_tools_only_for_somebody_who_may_use_them(): void
    {
        $recording = $this->recording(['skip_segments' => [['start' => 60, 'end' => 90, 'label' => 'Doors']]]);

        // A viewer is offered the skip button, never the marking of it - and the
        // prop is absent rather than false, so nothing about it reaches them.
        $this->actingAs($this->viewer)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn ($page) => $page->where('tools', null));

        $this->actingAs($this->admin)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn ($page) => $page
                ->where('tools.duration', 3600)
                ->where('tools.skipsUrl', route('recordings.skips', $recording))
            );
    }

    public function test_skips_can_be_marked_from_the_watch_page(): void
    {
        $recording = $this->recording();

        $this->actingAs($this->admin)
            ->patch(route('recordings.skips', $recording), [
                'skip_segments' => [
                    ['start' => 300, 'end' => 420, 'label' => 'Intermission'],
                    ['start' => 100, 'end' => 150, 'label' => null],
                ],
            ])
            ->assertRedirect();

        // Sorted and merged on the way in, like every other way they are written.
        $this->assertSame(
            [
                ['start' => 100, 'end' => 150, 'label' => null],
                ['start' => 300, 'end' => 420, 'label' => 'Intermission'],
            ],
            $recording->fresh()->skips(),
        );
    }

    public function test_marking_from_the_watch_page_needs_stream_manage(): void
    {
        $recording = $this->recording(['skip_segments' => []]);

        $this->actingAs($this->viewer)
            ->patch(route('recordings.skips', $recording), [
                'skip_segments' => [['start' => 10, 'end' => 20, 'label' => 'Nope']],
            ])
            ->assertForbidden();

        $this->assertSame([], $recording->fresh()->skip_segments);
    }

    public function test_the_watch_page_endpoint_cannot_touch_anything_but_the_skips(): void
    {
        $recording = $this->recording(['title' => 'Dance Competition', 'is_published' => true]);

        $this->actingAs($this->admin)
            ->patch(route('recordings.skips', $recording), [
                'skip_segments' => [],
                'title' => 'Renamed from the watch page',
                'is_published' => false,
            ]);

        $fresh = $recording->fresh();
        $this->assertSame('Dance Competition', $fresh->title);
        $this->assertTrue((bool) $fresh->is_published);
    }
}
