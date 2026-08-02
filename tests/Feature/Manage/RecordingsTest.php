<?php

namespace Tests\Feature\Manage;

use App\Jobs\ProcessRecordingJob;
use App\Models\Recording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class RecordingsTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'show_id' => '',
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony',
            'description' => 'The opening.',
            'date' => now()->format('Y-m-d\TH:i'),
            'duration' => '',
            'm3u8_url' => 'https://cdn.example.test/opening/index.m3u8',
            'thumbnail_path' => '',
            'is_published' => true,
            'required_roles' => [],
        ], $overrides);
    }

    public function test_the_list_loads(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Recordings/Index')
                ->has('table.rows'));
    }

    public function test_creating_queues_the_processing_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.recordings.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('recordings', ['slug' => 'opening-ceremony']);
        Queue::assertPushed(ProcessRecordingJob::class);
    }

    public function test_an_empty_show_and_duration_are_accepted_as_null(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.recordings.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $recording = Recording::where('slug', 'opening-ceremony')->firstOrFail();

        $this->assertNull($recording->show_id);
        $this->assertNull($recording->duration);
    }

    public function test_regenerating_a_thumbnail_clears_the_path_and_requeues(): void
    {
        Queue::fake();

        $recording = Recording::create($this->payload([
            'show_id' => null,
            'duration' => null,
            'thumbnail_path' => 'recordings/thumbnails/old.jpg',
            'date' => now(),
        ]));

        $this->actingAs($this->admin)
            ->post(route('manage.recordings.thumbnail', $recording))
            ->assertRedirect();

        $this->assertNull($recording->fresh()->thumbnail_path);
        Queue::assertPushed(ProcessRecordingJob::class);
    }

    public function test_a_moderator_cannot_write_recordings(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.recordings.store'), $this->payload())
            ->assertForbidden();
    }
}
