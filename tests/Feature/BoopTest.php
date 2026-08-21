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
 * Boops are unauthenticated by design, so what matters is that a click writes
 * nothing but a cache counter, that the tick banks the lot in one write, that
 * the room hears the first boop at once and the rest grouped behind it, that a
 * viewer cannot count faster than a hand, and that a show which is not live
 * takes none.
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

    public function test_the_tick_banks_every_boop_in_one_write(): void
    {
        Event::fake([ShowBooped::class]);
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live', 'boop_count' => 5]);

        $this->postJson(route('show.boop', $show->slug), ['count' => 10]);
        $this->postJson(route('show.boop', $show->slug), ['count' => 7]);
        $this->postJson(route('show.boop', $show->slug), ['count' => 3]);

        $this->flush();

        $this->assertSame(25, (int) $show->fresh()->boop_count);
    }

    public function test_the_first_boop_is_broadcast_at_once_and_the_rest_are_grouped(): void
    {
        Event::fake([ShowBooped::class]);
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live', 'boop_count' => 5]);

        // The first request owns the broadcast window, so the room hears it now.
        $this->postJson(route('show.boop', $show->slug), ['count' => 10]);

        Event::assertDispatchedTimes(ShowBooped::class, 1);
        Event::assertDispatched(ShowBooped::class, fn (ShowBooped $event) => $event->showId === $show->id
            && $event->total === 15
            && $event->delta === 10);

        // Everything behind it in the window waits for the tick, as one message.
        $this->postJson(route('show.boop', $show->slug), ['count' => 7]);
        $this->postJson(route('show.boop', $show->slug), ['count' => 3]);

        Event::assertDispatchedTimes(ShowBooped::class, 1);

        $this->flush();

        Event::assertDispatchedTimes(ShowBooped::class, 2);
        Event::assertDispatched(ShowBooped::class, fn (ShowBooped $event) => $event->total === 25
            && $event->delta === 10);
    }

    public function test_a_tick_broadcasts_nothing_the_request_path_already_announced(): void
    {
        Event::fake([ShowBooped::class]);
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live']);

        $this->postJson(route('show.boop', $show->slug), ['count' => 4]);

        $this->flush();
        $this->flush();

        Event::assertDispatchedTimes(ShowBooped::class, 1);
    }

    public function test_a_viewer_cannot_boop_faster_than_a_hand(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live']);

        // 400 a minute is the budget; the ninth batch of 50 is over it.
        for ($i = 0; $i < 8; $i++) {
            $this->postJson(route('show.boop', $show->slug), ['count' => 50])
                ->assertOk()
                ->assertJson(['accepted' => 50]);
        }

        $this->postJson(route('show.boop', $show->slug), ['count' => 50])
            ->assertStatus(429)
            ->assertJson(['accepted' => 0]);

        $this->flush();

        $this->assertSame(400, (int) $show->fresh()->boop_count);
    }

    public function test_a_batch_over_the_remaining_budget_is_trimmed_not_refused(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live']);

        for ($i = 0; $i < 7; $i++) {
            $this->postJson(route('show.boop', $show->slug), ['count' => 50])->assertOk();
        }

        $this->postJson(route('show.boop', $show->slug), ['count' => 30])
            ->assertOk()
            ->assertJson(['accepted' => 30]);

        $this->postJson(route('show.boop', $show->slug), ['count' => 50])
            ->assertOk()
            ->assertJson(['accepted' => 20, 'total' => 400]);

        $this->flush();

        $this->assertSame(400, (int) $show->fresh()->boop_count);
    }

    public function test_the_budget_is_counted_per_show(): void
    {
        $this->actAsViewer();

        $show = Show::factory()->create(['status' => 'live']);
        $other = Show::factory()->create(['status' => 'live']);

        for ($i = 0; $i < 8; $i++) {
            $this->postJson(route('show.boop', $show->slug), ['count' => 50])->assertOk();
        }

        $this->postJson(route('show.boop', $show->slug), ['count' => 10])->assertStatus(429);
        $this->postJson(route('show.boop', $other->slug), ['count' => 10])->assertOk();
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
