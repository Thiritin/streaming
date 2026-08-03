<?php

namespace Tests\Feature;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An ended or scheduled show keeps its own page instead of redirecting, so the page has
 * to offer somewhere to go. These cover the order that choice is made in.
 */
class PromotedShowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Source $primary;

    private Source $secondary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Ordered by priority descending, so the higher number is the primary channel.
        $this->primary = Source::factory()->create([
            'name' => 'Main Stage',
            'priority' => 100,
            'status' => SourceStatusEnum::ONLINE,
        ]);
        $this->secondary = Source::factory()->create([
            'name' => 'Stage B',
            'priority' => 10,
            'status' => SourceStatusEnum::ONLINE,
        ]);
    }

    private function endedShow(): Show
    {
        return Show::factory()->create([
            'source_id' => $this->secondary->id,
            'status' => 'ended',
            'title' => 'The Show That Ended',
            'actual_start' => now()->subHours(2),
            'actual_end' => now()->subHour(),
        ]);
    }

    private function promotedFor(Show $show): ?array
    {
        $response = $this->actingAs($this->user)->get(route('show.view', $show));
        $response->assertOk();

        return $response->viewData('page')['props']['promoted'] ?? null;
    }

    public function test_the_primary_channel_wins_when_it_is_live(): void
    {
        // A busier live show on another channel must still lose to the primary one.
        Show::factory()->create([
            'source_id' => $this->secondary->id,
            'status' => 'live',
            'title' => 'Busy Elsewhere',
            'viewer_count' => 5000,
        ]);
        Show::factory()->create([
            'source_id' => $this->primary->id,
            'status' => 'live',
            'title' => 'Main Stage Live',
            'viewer_count' => 10,
        ]);

        $promoted = $this->promotedFor($this->endedShow());

        $this->assertSame('Main Stage Live', $promoted['title']);
        $this->assertTrue($promoted['is_primary_channel']);
        $this->assertTrue($promoted['is_live']);
    }

    public function test_falls_back_to_the_busiest_live_show_when_the_primary_channel_is_dark(): void
    {
        Show::factory()->create([
            'source_id' => $this->secondary->id,
            'status' => 'live',
            'title' => 'Quiet One',
            'viewer_count' => 3,
        ]);
        Show::factory()->create([
            'source_id' => $this->secondary->id,
            'status' => 'live',
            'title' => 'Busiest One',
            'viewer_count' => 900,
        ]);

        $promoted = $this->promotedFor($this->endedShow());

        $this->assertSame('Busiest One', $promoted['title']);
        $this->assertFalse($promoted['is_primary_channel']);
    }

    public function test_falls_back_to_the_next_scheduled_show_when_nothing_is_live(): void
    {
        Show::factory()->create([
            'source_id' => $this->primary->id,
            'status' => 'scheduled',
            'title' => 'Later Today',
            'scheduled_start' => now()->addHours(4),
        ]);
        Show::factory()->create([
            'source_id' => $this->secondary->id,
            'status' => 'scheduled',
            'title' => 'Sooner',
            'scheduled_start' => now()->addHour(),
        ]);

        $promoted = $this->promotedFor($this->endedShow());

        $this->assertSame('Sooner', $promoted['title']);
        $this->assertFalse($promoted['is_live']);
    }

    public function test_never_promotes_the_show_being_viewed(): void
    {
        // The only live show is the one open, which happens when a viewer lands on a
        // show that has just gone live elsewhere in the tab.
        $show = Show::factory()->create([
            'source_id' => $this->primary->id,
            'status' => 'live',
            'title' => 'This Very Show',
            'viewer_count' => 1,
        ]);

        $this->assertNull($this->promotedFor($show));
    }

    public function test_promotes_nothing_when_there_is_nothing_else(): void
    {
        $this->assertNull($this->promotedFor($this->endedShow()));
    }
}
