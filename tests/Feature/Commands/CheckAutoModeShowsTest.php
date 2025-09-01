<?php

namespace Tests\Feature\Commands;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CheckAutoModeShowsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function command_starts_scheduled_shows_when_source_is_online()
    {
        // Arrange: Create sources and shows
        $onlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $offlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        // Auto mode show that should start (source online, past scheduled start)
        $showToStart = Show::factory()->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Auto mode show that should NOT start (source offline)
        $showNotToStart = Show::factory()->create([
            'source_id' => $offlineSource->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Manual mode show that should NOT start
        $manualShow = Show::factory()->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => false,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Act: Run the command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput('Checking auto mode shows...')
            ->expectsOutput("Starting auto mode show: {$showToStart->title}")
            ->expectsOutput("✓ Show '{$showToStart->title}' started successfully")
            ->expectsOutput('No shows to auto-end at this time.')
            ->expectsOutput('Auto mode check completed.')
            ->assertExitCode(0);

        // Assert
        $showToStart->refresh();
        $this->assertEquals('live', $showToStart->status);
        $this->assertNotNull($showToStart->actual_start);

        $showNotToStart->refresh();
        $this->assertEquals('scheduled', $showNotToStart->status);

        $manualShow->refresh();
        $this->assertEquals('scheduled', $manualShow->status);
    }

    /** @test */
    public function command_ends_live_shows_at_scheduled_end_time_regardless_of_source_status()
    {
        // Arrange
        $onlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $offlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        // Auto mode show with ONLINE source, past scheduled end (should end)
        $showWithOnlineSource = Show::factory()->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subMinutes(5),
            'actual_start' => now()->subHours(2),
        ]);

        // Auto mode show with OFFLINE source, past scheduled end (should also end)
        $showWithOfflineSource = Show::factory()->create([
            'source_id' => $offlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subMinutes(10),
            'actual_start' => now()->subHours(3),
        ]);

        // Auto mode show still within scheduled time (should NOT end)
        $showStillScheduled = Show::factory()->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        // Act: Run the command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput('Checking auto mode shows...')
            ->expectsOutput('No shows to auto-start at this time.')
            ->expectsOutput("Ending auto mode show: {$showWithOnlineSource->title}")
            ->expectsOutput("✓ Show '{$showWithOnlineSource->title}' ended successfully (scheduled end reached)")
            ->expectsOutput("Ending auto mode show: {$showWithOfflineSource->title}")
            ->expectsOutput("✓ Show '{$showWithOfflineSource->title}' ended successfully (scheduled end reached)")
            ->expectsOutput('Auto mode check completed.')
            ->assertExitCode(0);

        // Assert
        $showWithOnlineSource->refresh();
        $this->assertEquals('ended', $showWithOnlineSource->status);
        $this->assertNotNull($showWithOnlineSource->actual_end);

        $showWithOfflineSource->refresh();
        $this->assertEquals('ended', $showWithOfflineSource->status);
        $this->assertNotNull($showWithOfflineSource->actual_end);

        $showStillScheduled->refresh();
        $this->assertEquals('live', $showStillScheduled->status);
        $this->assertNull($showStillScheduled->actual_end);
    }

    /** @test */
    public function command_keeps_shows_live_during_scheduled_time()
    {
        // Arrange
        $offlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        $onlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        // Auto mode shows still within scheduled time (should NOT end)
        $showWithOfflineSource = Show::factory()->create([
            'source_id' => $offlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        $showWithOnlineSource = Show::factory()->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subMinutes(30),
            'scheduled_end' => now()->addMinutes(30),
            'actual_start' => now()->subMinutes(30),
        ]);

        // Act: Run the command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput('Checking auto mode shows...')
            ->expectsOutput('No shows to auto-start at this time.')
            ->expectsOutput('No shows to auto-end at this time.')
            ->expectsOutput('Auto mode check completed.')
            ->assertExitCode(0);

        // Assert: Both shows should remain live
        $showWithOfflineSource->refresh();
        $this->assertEquals('live', $showWithOfflineSource->status);
        $this->assertNull($showWithOfflineSource->actual_end);

        $showWithOnlineSource->refresh();
        $this->assertEquals('live', $showWithOnlineSource->status);
        $this->assertNull($showWithOnlineSource->actual_end);
    }

    /** @test */
    public function command_handles_no_shows_to_process()
    {
        // Arrange: Create only manual mode shows or future shows
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => false, // Manual mode
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addHours(2),
        ]);

        Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(), // Future show
            'scheduled_end' => now()->addHours(3),
        ]);

        // Act: Run the command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput('Checking auto mode shows...')
            ->expectsOutput('No shows to auto-start at this time.')
            ->expectsOutput('No shows to auto-end at this time.')
            ->expectsOutput('Auto mode check completed.')
            ->assertExitCode(0);
    }

    /** @test */
    public function command_processes_multiple_shows_correctly()
    {
        // Arrange: Create multiple shows with different conditions
        $onlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $offlineSource = Source::factory()->create([
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        // Create 3 shows that should start
        $showsToStart = Show::factory()->count(3)->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => true,
            'status' => 'scheduled',
            'scheduled_start' => now()->subMinutes(15),
            'scheduled_end' => now()->addHours(2),
        ]);

        // Create 2 shows that should end (past scheduled end time)
        $showsToEnd = Show::factory()->count(2)->create([
            'source_id' => $offlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subMinutes(5),
            'actual_start' => now()->subHours(3),
        ]);
        
        // Also create a show with online source that should end (past scheduled end)
        $showWithOnlineSourceToEnd = Show::factory()->create([
            'source_id' => $onlineSource->id,
            'auto_mode' => true,
            'status' => 'live',
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subMinutes(10),
            'actual_start' => now()->subHours(2),
        ]);

        // Act: Run the command
        $this->artisan('shows:check-auto-mode')
            ->assertExitCode(0);

        // Assert: All appropriate shows were processed
        foreach ($showsToStart as $show) {
            $show->refresh();
            $this->assertEquals('live', $show->status);
            $this->assertNotNull($show->actual_start);
        }

        foreach ($showsToEnd as $show) {
            $show->refresh();
            $this->assertEquals('ended', $show->status);
            $this->assertNotNull($show->actual_end);
        }
        
        // Also verify the show with online source was ended
        $showWithOnlineSourceToEnd->refresh();
        $this->assertEquals('ended', $showWithOnlineSourceToEnd->status);
        $this->assertNotNull($showWithOnlineSourceToEnd->actual_end);
    }

    /** @test */
    public function command_does_not_start_already_live_shows()
    {
        // Arrange: Create a show that is already live
        $source = Source::factory()->create([
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $liveShow = Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => true,
            'status' => 'live', // Already live
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        // Act: Run the command
        $this->artisan('shows:check-auto-mode')
            ->expectsOutput('Checking auto mode shows...')
            ->expectsOutput('No shows to auto-start at this time.')
            ->expectsOutput('No shows to auto-end at this time.')
            ->expectsOutput('Auto mode check completed.')
            ->assertExitCode(0);

        // Assert: Show remains unchanged
        $liveShow->refresh();
        $this->assertEquals('live', $liveShow->status);
        $this->assertEquals(now()->subHour()->format('Y-m-d H:i'), $liveShow->actual_start->format('Y-m-d H:i'));
    }
}