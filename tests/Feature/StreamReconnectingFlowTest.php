<?php

namespace Tests\Feature;

use App\Enum\SourceStatusEnum;
use App\Events\SourceStatusChangedEvent;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamReconnectingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconnecting_state_triggers_when_source_goes_from_offline_to_online()
    {
        Event::fake();

        // Create a source that's initially offline
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
            'stream_key' => 'test-key-123',
        ]);

        // Create a live show using this source
        $show = Show::factory()->create([
            'source_id' => $source->id,
            'status' => 'live',
            'slug' => 'test-show',
        ]);

        // User visits the show page
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('show.view', $show->slug));
        
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ShowPlayer')
            ->has('currentShow', fn ($page) => $page
                ->where('id', $show->id)
                ->where('source.status', 'offline')
                ->etc()
            )
        );

        // Simulate source coming online (would trigger reconnecting in frontend)
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();

        // Verify the event is dispatched
        Event::assertDispatched(SourceStatusChangedEvent::class, function ($event) use ($source) {
            return $event->source->id === $source->id && 
                   $event->status === 'online';
        });
    }

    public function test_reconnecting_state_triggers_when_source_goes_from_error_to_online()
    {
        Event::fake();

        // Create a source that's in error state
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ERROR,
            'stream_key' => 'test-key-456',
        ]);

        // Create a live show using this source
        $show = Show::factory()->create([
            'source_id' => $source->id,
            'status' => 'live',
            'slug' => 'error-test-show',
        ]);

        // User visits the show page
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('show.view', $show->slug));
        
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ShowPlayer')
            ->has('currentShow', fn ($page) => $page
                ->where('id', $show->id)
                ->where('source.status', 'error')
                ->etc()
            )
        );

        // Simulate source recovering to online
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();

        // Verify the event is dispatched
        Event::assertDispatched(SourceStatusChangedEvent::class, function ($event) use ($source) {
            return $event->source->id === $source->id && 
                   $event->status === 'online';
        });
    }

    public function test_hls_url_generation_for_live_show()
    {
        // Create an online source
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
            'stream_key' => 'live-key-789',
        ]);

        // Create a live show
        $show = Show::factory()->create([
            'source_id' => $source->id,
            'status' => 'live',
            'slug' => 'live-show',
        ]);

        // User visits the show page
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('show.view', $show->slug));
        
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ShowPlayer')
            ->has('initialHlsUrls')
            ->has('currentShow', fn ($page) => $page
                ->where('id', $show->id)
                ->where('status', 'live')
                ->where('source.status', 'online')
                ->etc()
            )
        );
    }

    public function test_no_reconnecting_for_offline_to_error_transition()
    {
        Event::fake();

        // Create a source that's offline
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
            'stream_key' => 'offline-key',
        ]);

        // Create a show
        $show = Show::factory()->create([
            'source_id' => $source->id,
            'status' => 'live',
            'slug' => 'offline-show',
        ]);

        // Simulate source going to error (should not trigger reconnecting)
        $source->status = SourceStatusEnum::ERROR;
        $source->save();

        // Verify the event is dispatched but with error status
        Event::assertDispatched(SourceStatusChangedEvent::class, function ($event) use ($source) {
            return $event->source->id === $source->id && 
                   $event->status === 'error';
        });
    }
}