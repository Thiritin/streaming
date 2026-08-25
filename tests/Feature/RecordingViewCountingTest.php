<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Views count viewers, not renders.
 *
 * The watch page is an Inertia visit like any other, so a reload or a comment posted
 * renders it again. Counting each one put every viewer of a popular recording behind
 * the same row lock, which is what took the site down; these pin the window instead.
 */
class RecordingViewCountingTest extends TestCase
{
    use RefreshDatabase;

    private function recording(): Recording
    {
        return Recording::create([
            'title' => 'A show',
            'date' => now()->subDay(),
            'duration' => 3600,
            'views' => 0,
            'is_published' => true,
            'status' => 'ready',
            'm3u8_url' => 'https://example.test/a.m3u8',
        ]);
    }

    public function test_a_reload_inside_the_window_counts_once(): void
    {
        $this->actingAs(User::factory()->create());
        $recording = $this->recording();

        $this->get(route('recordings.show', $recording))->assertOk();
        $this->get(route('recordings.show', $recording))->assertOk();
        $this->get(route('recordings.show', $recording))->assertOk();

        $this->assertSame(1, $recording->fresh()->views);
    }

    public function test_the_same_viewer_counts_again_once_the_window_is_out(): void
    {
        $this->actingAs(User::factory()->create());
        $recording = $this->recording();

        $this->get(route('recordings.show', $recording))->assertOk();

        $this->travel(31)->minutes();

        $this->get(route('recordings.show', $recording))->assertOk();

        $this->assertSame(2, $recording->fresh()->views);
    }

    public function test_another_viewer_counts_on_their_own(): void
    {
        $recording = $this->recording();

        $this->actingAs(User::factory()->create());
        $this->get(route('recordings.show', $recording))->assertOk();

        $this->actingAs(User::factory()->create());
        $this->get(route('recordings.show', $recording))->assertOk();

        $this->assertSame(2, $recording->fresh()->views);
    }

    public function test_counting_a_view_is_not_an_edit(): void
    {
        $this->actingAs(User::factory()->create());
        $recording = $this->recording();
        $before = $recording->updated_at;

        $this->travel(5)->minutes();

        $this->get(route('recordings.show', $recording))->assertOk();

        $fresh = $recording->fresh();

        $this->assertSame(1, $fresh->views);
        $this->assertTrue($before->equalTo($fresh->updated_at));
    }
}
