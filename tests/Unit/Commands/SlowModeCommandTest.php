<?php

namespace Tests\Unit\Commands;

use Tests\TestCase;
use App\Console\Commands\Chat\SlowModeCommand;
use App\Models\User;
use App\Models\Role;
use App\Models\ChatSetting;
use App\Events\CommandFeedbackEvent;
use App\Events\SystemMessageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class SlowModeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected SlowModeCommand $command;
    protected User $admin;
    protected User $moderator;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->command = new SlowModeCommand();
        
        // Create roles
        Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'chat_color' => '#ff0000',
            'is_staff' => true,
        ]);
        
        Role::create([
            'name' => 'Moderator',
            'slug' => 'moderator',
            'chat_color' => '#00ff00',
            'is_staff' => true,
        ]);
        
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        
        $this->moderator = User::factory()->create();
        $this->moderator->assignRole('moderator');
        
        $this->regularUser = User::factory()->create();
        
        // Clear cache
        Cache::flush();
    }

    public function test_command_has_correct_metadata()
    {
        $this->assertEquals('slowmode', $this->command->name());
        $this->assertEquals('/slowmode [seconds|off]', $this->command->signature());
        $this->assertEquals('Enable or configure slow mode for chat', $this->command->description());
        $this->assertContains('slow', $this->command->aliases());
    }

    public function test_admin_can_enable_slow_mode()
    {
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => '10'
        ]);
        
        // Check setting was created
        $this->assertDatabaseHas('chat_settings', [
            'key' => 'slow_mode_seconds',
            'value' => '10',
        ]);
        
        // Check cache was set
        $this->assertEquals(10, Cache::get('chat.slow_mode'));
        
        // Check events were dispatched
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'Slow mode enabled: 10 seconds');
        });
        
        Event::assertDispatched(SystemMessageEvent::class);
    }

    public function test_moderator_can_enable_slow_mode()
    {
        $this->assertTrue($this->command->authorize($this->moderator));
        
        Event::fake();
        
        $this->command->handle($this->moderator, [
            'duration' => '30'
        ]);
        
        $this->assertDatabaseHas('chat_settings', [
            'key' => 'slow_mode_seconds',
            'value' => '30',
        ]);
    }

    public function test_regular_user_cannot_enable_slow_mode()
    {
        $this->assertFalse($this->command->authorize($this->regularUser));
    }

    public function test_disable_slow_mode_with_off()
    {
        // First enable slow mode
        ChatSetting::create([
            'key' => 'slow_mode_seconds',
            'value' => '15',
        ]);
        Cache::put('chat.slow_mode', 15, now()->addHours(24));
        
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => 'off'
        ]);
        
        // Check setting was updated
        $this->assertDatabaseHas('chat_settings', [
            'key' => 'slow_mode_seconds',
            'value' => '0',
        ]);
        
        // Check cache was cleared
        $this->assertFalse(Cache::has('chat.slow_mode'));
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'Slow mode has been disabled');
        });
    }

    public function test_disable_slow_mode_with_zero()
    {
        ChatSetting::create([
            'key' => 'slow_mode_seconds',
            'value' => '10',
        ]);
        
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => '0'
        ]);
        
        $this->assertDatabaseHas('chat_settings', [
            'key' => 'slow_mode_seconds',
            'value' => '0',
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'disabled');
        });
    }

    public function test_check_current_status_when_no_parameter()
    {
        // Set current slow mode
        ChatSetting::create([
            'key' => 'slow_mode_seconds',
            'value' => '20',
        ]);
        
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => null
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'currently set to 20 seconds');
        });
    }

    public function test_check_status_when_disabled()
    {
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => null
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'currently disabled');
        });
    }

    public function test_invalid_duration_shows_error()
    {
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => 'invalid'
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'must be a positive number');
        });
        
        $this->assertDatabaseMissing('chat_settings', [
            'key' => 'slow_mode_seconds',
        ]);
    }

    public function test_negative_duration_shows_error()
    {
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => '-5'
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'must be a positive number');
        });
    }

    public function test_duration_limits()
    {
        Event::fake();
        
        // Test too short (0 seconds)
        $this->command->handle($this->admin, [
            'duration' => '0.5'
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'between 1 and 300 seconds');
        });
        
        // Test too long (over 300 seconds)
        $this->command->handle($this->admin, [
            'duration' => '301'
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'between 1 and 300 seconds');
        });
        
        // Test valid range
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => '60'
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'Slow mode enabled: 60 seconds');
        });
    }

    public function test_update_existing_slow_mode_setting()
    {
        // Create initial setting
        $setting = ChatSetting::create([
            'key' => 'slow_mode_seconds',
            'value' => '5',
        ]);
        
        Event::fake();
        
        $this->command->handle($this->admin, [
            'duration' => '15'
        ]);
        
        // Should update existing setting, not create new one
        $this->assertDatabaseCount('chat_settings', 1);
        
        $setting->refresh();
        $this->assertEquals('15', $setting->value);
    }

    public function test_command_provides_examples()
    {
        $examples = $this->command->examples();
        
        $this->assertIsArray($examples);
        $this->assertArrayHasKey('/slowmode', $examples);
        $this->assertArrayHasKey('/slowmode 10', $examples);
        $this->assertArrayHasKey('/slowmode off', $examples);
        $this->assertArrayHasKey('/slow 5', $examples);
    }

    public function test_slow_mode_cache_is_persistent()
    {
        $this->command->handle($this->admin, [
            'duration' => '45'
        ]);
        
        // Check cache is set with TTL
        $this->assertEquals(45, Cache::get('chat.slow_mode'));
        
        // Check cache key exists
        $this->assertTrue(Cache::has('chat.slow_mode'));
    }
}