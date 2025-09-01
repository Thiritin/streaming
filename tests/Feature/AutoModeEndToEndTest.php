<?php

namespace Tests\Feature;

use App\Enum\SourceStatusEnum;
use App\Events\SourceStatusChangedEvent;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AutoModeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function complete_auto_mode_show_lifecycle()
    {
        // Setup: Create a source and an auto mode show
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
            'name' => 'Test Stream Source',
        ]);

        $show = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Auto Mode Test Show',
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(5),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Step 1: Show is scheduled, source is offline - nothing happens
        $this->assertEquals('scheduled', $show->status);
        $this->assertEquals(SourceStatusEnum::OFFLINE, $source->status);

        // Step 2: Time passes, we reach scheduled start time but source is still offline
        $this->travel(10)->minutes();
        
        // Run the scheduled command
        $this->artisan('shows:check-auto-mode')->assertExitCode(0);
        
        $show->refresh();
        $this->assertEquals('scheduled', $show->status); // Still scheduled because source is offline

        // Step 3: Source goes online (simulating OBS starting to stream)
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();
        
        // Broadcast the event (this would normally happen via SRS callback or admin panel)
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::OFFLINE->value));
        
        $show->refresh();
        $this->assertEquals('live', $show->status); // Show auto-started via event listener
        $this->assertNotNull($show->actual_start);

        // Step 4: Source has connection issues during the show (within scheduled time)
        $this->travel(30)->minutes();
        
        $source->status = SourceStatusEnum::ERROR;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::ONLINE->value));
        
        $show->refresh();
        $this->assertEquals('live', $show->status); // Show remains live during scheduled time

        // Step 5: Source recovers
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::ERROR->value));
        
        $show->refresh();
        $this->assertEquals('live', $show->status); // Show still live

        // Step 6: Time passes, we're now past the scheduled end time
        $this->travel(2)->hours();
        
        // Run the scheduled command (show should end at scheduled time even if source is online)
        $this->artisan('shows:check-auto-mode')->assertExitCode(0);
        
        $show->refresh();
        $this->assertEquals('ended', $show->status); // Show auto-ended at scheduled time
        $this->assertNotNull($show->actual_end);
        
        // Verify that source going offline after show already ended doesn't cause issues
        $source->status = SourceStatusEnum::OFFLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::ONLINE->value));
        
        $show->refresh();
        $this->assertEquals('ended', $show->status); // Show remains ended
    }

    /** @test */
    public function auto_mode_show_starts_via_scheduled_command_when_source_already_online()
    {
        // Setup: Source is already online before scheduled start
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
            'name' => 'Already Online Source',
        ]);

        $show = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Scheduled Start Test',
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(5),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Source is online but show hasn't reached scheduled start yet
        $this->artisan('shows:check-auto-mode')->assertExitCode(0);
        $show->refresh();
        $this->assertEquals('scheduled', $show->status);

        // Time passes to scheduled start
        $this->travel(6)->minutes();
        
        // Run the scheduled command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput("Starting auto mode show: {$show->title}")
            ->assertExitCode(0);
        
        $show->refresh();
        $this->assertEquals('live', $show->status);
        $this->assertNotNull($show->actual_start);
    }

    /** @test */
    public function manual_mode_show_is_not_affected_by_source_status_changes()
    {
        // Setup: Create a manual mode show
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        $show = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Manual Mode Show',
            'auto_mode' => false, // Manual mode
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Source goes online
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::OFFLINE->value));
        
        $show->refresh();
        $this->assertEquals('scheduled', $show->status); // Remains scheduled

        // Run scheduled command
        $this->artisan('shows:check-auto-mode')->assertExitCode(0);
        
        $show->refresh();
        $this->assertEquals('scheduled', $show->status); // Still scheduled

        // Manually start the show
        $show->goLive();
        $this->assertEquals('live', $show->status);

        // Time passes beyond scheduled end
        $this->travel(3)->hours();
        
        // Source goes offline
        $source->status = SourceStatusEnum::OFFLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::ONLINE->value));
        
        $show->refresh();
        $this->assertEquals('live', $show->status); // Still live, not auto-ended

        // Manual end required
        $show->endLivestream();
        $this->assertEquals('ended', $show->status);
    }

    /** @test */
    public function multiple_auto_mode_shows_on_same_source_are_handled_correctly()
    {
        // Setup: One source with multiple scheduled shows
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        // Morning show
        $morningShow = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Morning Show',
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addMinutes(30),
        ]);

        // Evening show
        $eveningShow = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Evening Show',
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(3),
            'scheduled_end' => now()->addHours(5),
        ]);

        // Source goes online
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::OFFLINE->value));

        // Only morning show should start (past its scheduled start)
        $morningShow->refresh();
        $eveningShow->refresh();
        $this->assertEquals('live', $morningShow->status);
        $this->assertEquals('scheduled', $eveningShow->status);

        // Time passes, morning show ends
        $this->travel(40)->minutes();
        
        $source->status = SourceStatusEnum::OFFLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::ONLINE->value));

        $morningShow->refresh();
        $this->assertEquals('ended', $morningShow->status);

        // Time passes to evening show
        $this->travel(3)->hours();
        
        // Source comes back online
        $source->status = SourceStatusEnum::ONLINE;
        $source->save();
        event(new SourceStatusChangedEvent($source, SourceStatusEnum::OFFLINE->value));

        $eveningShow->refresh();
        $this->assertEquals('live', $eveningShow->status);
    }

    /** @test */
    public function auto_mode_show_ends_at_scheduled_time_even_with_online_source()
    {
        // Setup: Source is online and show is live
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
            'name' => 'Always Online Source',
        ]);

        $show = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Timed Show',
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->addMinutes(5),
            'actual_start' => now()->subHours(2),
        ]);

        // Show is live and source is online
        $this->assertEquals('live', $show->status);
        $this->assertEquals(SourceStatusEnum::ONLINE, $source->status);

        // Time passes to just after scheduled end
        $this->travel(6)->minutes();
        
        // Run the scheduled command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput("Ending auto mode show: {$show->title}")
            ->assertExitCode(0);
        
        $show->refresh();
        $source->refresh();
        
        // Show should be ended even though source is still online
        $this->assertEquals('ended', $show->status);
        $this->assertNotNull($show->actual_end);
        $this->assertEquals(SourceStatusEnum::ONLINE, $source->status); // Source remains online
    }

    /** @test */
    public function auto_mode_respects_show_status_transitions()
    {
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        // Create a cancelled show
        $cancelledShow = Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => true,
            'status' => 'cancelled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHour(),
        ]);

        // Create an ended show
        $endedShow = Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => true,
            'status' => 'ended',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHour(),
            'actual_start' => now()->subHours(3),
            'actual_end' => now()->subHour(),
        ]);

        // Run command - neither should change
        $this->artisan('shows:check-auto-mode')->assertExitCode(0);

        $cancelledShow->refresh();
        $endedShow->refresh();

        $this->assertEquals('cancelled', $cancelledShow->status);
        $this->assertEquals('ended', $endedShow->status);
    }
}