<?php

namespace Tests\Feature;

use App\Events\ShowBooped;
use App\Jobs\FlushShowBoopsJob;
use App\Models\Show;
use App\Models\User;
use App\Services\BoopCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Boops are unauthenticated and unlimited by design, so what matters is that a
 * click writes nothing but a cache counter, that the tick banks the lot in one
 * write and one broadcast, and that a show which is not live takes none.
 */
class BoopTest extends TestCase
{
    use RefreshDatabase;

    private function actAsViewer(): void
    {
        $this->actingAs(User::factory()->create());
    }

    private function flush(): void
    {
        app(FlushShowBoopsJob::class)->handle(app(BoopCounter::class));
    }

    public function test_a_viewer_can_boop_a_live_show(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live', 'boop_count' => 5]);

        $this->postJson(route('show.boop', $show->slug), ['count' => 3])
            ->assertOk()
            ->assertJson(['total' => 8, 'accepted' => 3]);
    }

    public function test_a_click_does_not_write_to_the_database(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live', 'boop_count' => 5]);

        $this->postJson(route('show.boop', $show->slug), ['count' => 3]);

        $this->assertSame(5, (int) $show->fresh()->boop_count);
    }

    public function test_the_tick_banks_every_boop_in_one_write_and_one_broadcast(): void
    {
        Event::fake([ShowBooped::class]);
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live', 'boop_count' => 5]);

        $this->postJson(route('show.boop', $show->slug), ['count' => 10]);
        $this->postJson(route('show.boop', $show->slug), ['count' => 7]);
        $this->postJson(route('show.boop', $show->slug), ['count' => 3]);

        $this->flush();

        $this->assertSame(25, (int) $show->fresh()->boop_count);

        Event::assertDispatchedTimes(ShowBooped::class, 1);
        Event::assertDispatched(ShowBooped::class, fn (ShowBooped $event) => $event->showId === $show->id
            && $event->total === 25
            && $event->delta === 20);
    }

    public function test_a_tick_with_nothing_pending_broadcasts_nothing(): void
    {
        Event::fake([ShowBooped::class]);

        Show::factory()->create(['status' => 'live']);

        $this->flush();

        Event::assertNotDispatched(ShowBooped::class);
    }

    public function test_a_guest_can_boop_when_login_is_optional(): void
    {
        Config::set('auth.required', false);

        $show = Show::factory()->create(['status' => 'live']);

        $this->post(route('show.boop', $show->slug), ['count' => 2], ['Accept' => 'application/json'])
            ->assertOk();

        $this->flush();

        $this->assertSame(2, (int) $show->fresh()->boop_count);
    }

    public function test_a_batch_over_the_cap_is_rejected(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live']);

        $this->postJson(route('show.boop', $show->slug), ['count' => 51])
            ->assertStatus(422);

        $this->flush();

        $this->assertSame(0, (int) $show->fresh()->boop_count);
    }

    public function test_a_show_that_is_not_live_takes_no_boops(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'ended', 'boop_count' => 12]);

        $this->postJson(route('show.boop', $show->slug), ['count' => 4])
            ->assertStatus(409)
            ->assertJson(['total' => 12, 'accepted' => 0]);

        $this->flush();

        $this->assertSame(12, (int) $show->fresh()->boop_count);
    }
}
