<?php

namespace Tests\Unit\Listeners;

use App\Enum\SourceStatusEnum;
use App\Events\SourceStatusChangedEvent;
use App\Listeners\HandleAutoModeShowsListener;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HandleAutoModeShowsListenerTest extends TestCase
{
    use RefreshDatabase;

    private HandleAutoModeShowsListener $listener;
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->listener = new HandleAutoModeShowsListener();
        $this->source = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);
    }

    /** @test */
    public function it_starts_scheduled_show_when_source_goes_online_after_scheduled_start()
    {
        // Arrange: Create an auto mode show that should have started 5 minutes ago
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(5),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Act: Source goes online
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::OFFLINE->value);
        $this->listener->handle($event);

        // Assert: Show should be live
        $show->refresh();
        $this->assertEquals('live', $show->status);
        $this->assertNotNull($show->actual_start);
    }

    /** @test */
    public function it_does_not_start_show_when_source_goes_online_before_scheduled_start()
    {
        // Arrange: Create an auto mode show scheduled for the future
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(1),
            'scheduled_end' => now()->addHours(3),
        ]);

        // Act: Source goes online before scheduled start
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::OFFLINE->value);
        $this->listener->handle($event);

        // Assert: Show should remain scheduled
        $show->refresh();
        $this->assertEquals('scheduled', $show->status);
        $this->assertNull($show->actual_start);
    }

    /** @test */
    public function it_ends_live_show_when_source_goes_offline_after_scheduled_end()
    {
        // Arrange: Create an auto mode show that is live and past its scheduled end
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subMinutes(10),
            'actual_start' => now()->subHours(3),
        ]);

        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Act: Source goes offline after scheduled end
        $this->source->status = SourceStatusEnum::OFFLINE;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::ONLINE->value);
        $this->listener->handle($event);

        // Assert: Show should be ended
        $show->refresh();
        $this->assertEquals('ended', $show->status);
        $this->assertNotNull($show->actual_end);
    }

    /** @test */
    public function it_keeps_show_live_when_source_goes_offline_during_scheduled_time()
    {
        // Arrange: Create an auto mode show that is live and within scheduled time
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Act: Source goes offline during scheduled time
        $this->source->status = SourceStatusEnum::OFFLINE;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::ONLINE->value);
        $this->listener->handle($event);

        // Assert: Show should remain live
        $show->refresh();
        $this->assertEquals('live', $show->status);
        $this->assertNull($show->actual_end);
    }

    /** @test */
    public function it_does_nothing_when_source_goes_to_error_during_scheduled_time()
    {
        // Arrange: Create an auto mode show that is live and within scheduled time
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Act: Source goes to error during scheduled time
        $this->source->status = SourceStatusEnum::ERROR;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::ONLINE->value);
        $this->listener->handle($event);

        // Assert: Show should remain live
        $show->refresh();
        $this->assertEquals('live', $show->status);
        $this->assertNull($show->actual_end);
    }

    /** @test */
    public function it_ends_show_when_source_goes_to_error_after_scheduled_end()
    {
        // Arrange: Create an auto mode show that is live and past its scheduled end
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subMinutes(10),
            'actual_start' => now()->subHours(3),
        ]);

        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Act: Source goes to error after scheduled end
        $this->source->status = SourceStatusEnum::ERROR;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::ONLINE->value);
        $this->listener->handle($event);

        // Assert: Show should be ended
        $show->refresh();
        $this->assertEquals('ended', $show->status);
        $this->assertNotNull($show->actual_end);
    }

    /** @test */
    public function it_ignores_manual_mode_shows()
    {
        // Arrange: Create a manual mode show (auto_mode = false)
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => false,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(5),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Act: Source goes online
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::OFFLINE->value);
        $this->listener->handle($event);

        // Assert: Show should remain scheduled (not affected by auto mode)
        $show->refresh();
        $this->assertEquals('scheduled', $show->status);
        $this->assertNull($show->actual_start);
    }

    /** @test */
    public function it_handles_multiple_auto_mode_shows_for_same_source()
    {
        // Arrange: Create multiple auto mode shows for the same source
        $show1 = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHour(),
        ]);

        $show2 = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(5),
            'scheduled_end' => now()->addHours(2),
        ]);

        $show3 = Show::factory()->create([
            'source_id' => $this->source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(), // Future show
            'scheduled_end' => now()->addHours(3),
        ]);

        // Act: Source goes online
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        $event = new SourceStatusChangedEvent($this->source, SourceStatusEnum::OFFLINE->value);
        $this->listener->handle($event);

        // Assert: Only shows past their scheduled start should be live
        $show1->refresh();
        $show2->refresh();
        $show3->refresh();

        $this->assertEquals('live', $show1->status);
        $this->assertEquals('live', $show2->status);
        $this->assertEquals('scheduled', $show3->status);
    }
}