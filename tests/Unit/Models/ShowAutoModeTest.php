<?php

namespace Tests\Unit\Models;

use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowAutoModeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function is_auto_mode_returns_true_when_auto_mode_is_enabled()
    {
        $show = Show::factory()->create(['auto_mode' => true]);
        $this->assertTrue($show->isAutoMode());
    }

    /** @test */
    public function is_auto_mode_returns_false_when_auto_mode_is_disabled()
    {
        $show = Show::factory()->create(['auto_mode' => false]);
        $this->assertFalse($show->isAutoMode());
    }

    /** @test */
    public function is_within_scheduled_time_returns_true_when_current_time_is_between_start_and_end()
    {
        $show = Show::factory()->create([
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $this->assertTrue($show->isWithinScheduledTime());
    }

    /** @test */
    public function is_within_scheduled_time_returns_false_when_current_time_is_before_start()
    {
        $show = Show::factory()->create([
            'scheduled_start' => now()->addMinutes(30),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->assertFalse($show->isWithinScheduledTime());
    }

    /** @test */
    public function is_within_scheduled_time_returns_false_when_current_time_is_after_end()
    {
        $show = Show::factory()->create([
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHour(),
        ]);

        $this->assertFalse($show->isWithinScheduledTime());
    }

    /** @test */
    public function is_within_scheduled_time_includes_boundaries()
    {
        // Use Carbon's setTestNow to freeze time for this test
        $testTime = now()->startOfMinute(); // Use start of minute for clean comparisons
        $this->travelTo($testTime);
        
        // Test exact start time
        $show = Show::factory()->create([
            'scheduled_start' => $testTime->copy(),
            'scheduled_end' => $testTime->copy()->addHour(),
        ]);
        $this->assertTrue($show->isWithinScheduledTime());

        // Test exact end time
        $show2 = Show::factory()->create([
            'scheduled_start' => $testTime->copy()->subHour(),
            'scheduled_end' => $testTime->copy(),
        ]);
        $this->assertTrue($show2->isWithinScheduledTime());
        
        // Clean up
        $this->travelBack();
    }

    /** @test */
    public function is_past_scheduled_end_returns_true_when_current_time_is_after_scheduled_end()
    {
        $show = Show::factory()->create([
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subMinutes(30),
        ]);

        $this->assertTrue($show->isPastScheduledEnd());
    }

    /** @test */
    public function is_past_scheduled_end_returns_false_when_current_time_is_before_scheduled_end()
    {
        $show = Show::factory()->create([
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addMinutes(30),
        ]);

        $this->assertFalse($show->isPastScheduledEnd());
    }

    /** @test */
    public function is_past_scheduled_end_returns_false_when_current_time_equals_scheduled_end()
    {
        // Use Carbon's setTestNow to freeze time for this test
        $testTime = now()->startOfMinute();
        $this->travelTo($testTime);
        
        $show = Show::factory()->create([
            'scheduled_start' => $testTime->copy()->subHour(),
            'scheduled_end' => $testTime->copy(),
        ]);

        $this->assertFalse($show->isPastScheduledEnd());
        
        // Clean up
        $this->travelBack();
    }

    /** @test */
    public function auto_mode_scope_returns_only_auto_mode_shows()
    {
        // Create mixed shows
        $autoShow1 = Show::factory()->create(['auto_mode' => true]);
        $autoShow2 = Show::factory()->create(['auto_mode' => true]);
        $manualShow1 = Show::factory()->create(['auto_mode' => false]);
        $manualShow2 = Show::factory()->create(['auto_mode' => false]);

        // Query using scope
        $autoModeShows = Show::autoMode()->get();

        // Assert
        $this->assertCount(2, $autoModeShows);
        $this->assertTrue($autoModeShows->contains($autoShow1));
        $this->assertTrue($autoModeShows->contains($autoShow2));
        $this->assertFalse($autoModeShows->contains($manualShow1));
        $this->assertFalse($autoModeShows->contains($manualShow2));
    }

    /** @test */
    public function auto_mode_scope_can_be_chained_with_other_scopes()
    {
        $source = Source::factory()->create();

        // Create shows with different statuses and modes
        Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => true,
            'status' => 'live',
        ]);
        
        Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => true,
            'status' => 'scheduled',
        ]);
        
        Show::factory()->create([
            'source_id' => $source->id,
            'auto_mode' => false,
            'status' => 'live',
        ]);

        // Query using chained scopes
        $autoModeLiveShows = Show::autoMode()->live()->get();

        // Assert
        $this->assertCount(1, $autoModeLiveShows);
        $this->assertTrue($autoModeLiveShows->first()->auto_mode);
        $this->assertEquals('live', $autoModeLiveShows->first()->status);
    }

    /** @test */
    public function go_live_method_works_with_auto_mode_shows()
    {
        $show = Show::factory()->create([
            'auto_mode' => true,
            'status' => 'scheduled',
            'actual_start' => null,
        ]);

        $show->goLive();

        $this->assertEquals('live', $show->status);
        $this->assertNotNull($show->actual_start);
        $this->assertTrue($show->auto_mode); // Auto mode should remain unchanged
    }

    /** @test */
    public function end_livestream_method_works_with_auto_mode_shows()
    {
        $show = Show::factory()->create([
            'auto_mode' => true,
            'status' => 'live',
            'actual_start' => now()->subHours(2),
            'actual_end' => null,
        ]);

        $show->endLivestream();

        $this->assertEquals('ended', $show->status);
        $this->assertNotNull($show->actual_end);
        $this->assertTrue($show->auto_mode); // Auto mode should remain unchanged
    }
}