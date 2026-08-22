<?php

namespace Tests\Feature\Manage;

use App\Jobs\ProcessRecordingJob;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
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

    /**
     * The show list spans every event the installation has run, and two of them will have
     * a show called "Opening Ceremony". Years are what tells them apart.
     */
    public function test_the_show_select_is_grouped_by_year(): void
    {
        $source = Source::factory()->create(['name' => 'Main Stage']);

        Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Opening Ceremony',
            'scheduled_start' => '2025-08-20 18:00:00',
        ]);
        Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Opening Ceremony',
            'scheduled_start' => '2026-08-20 18:00:00',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.create'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $shows = collect($page->toArray()['props']['options']['shows']);

                // The placeholder carries no group, so it stays above the headings rather
                // than being filed under a year it has nothing to do with.
                $this->assertSame('', $shows->first()['value']);
                $this->assertArrayNotHasKey('group', $shows->first());

                $groups = $shows->skip(1)->pluck('group');

                $this->assertEquals(['2026', '2025'], $groups->unique()->values()->all());
                $this->assertSame('Opening Ceremony (Main Stage)', $shows->skip(1)->first()['label']);
            });
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
