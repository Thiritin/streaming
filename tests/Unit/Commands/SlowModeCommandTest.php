<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\Chat\SlowModeCommand;
use App\Events\Chat\Broadcasts\ChatSettingsUpdatedEvent;
use App\Events\CommandFeedbackEvent;
use App\Models\ChatSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Chat\ChatSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

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

        $this->command = new SlowModeCommand;

        Role::create(['name' => 'Admin', 'slug' => 'admin', 'chat_color' => '#ff0000', 'is_staff' => true]);
        Role::create(['name' => 'Moderator', 'slug' => 'moderator', 'chat_color' => '#00ff00', 'is_staff' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->moderator = User::factory()->create();
        $this->moderator->assignRole('moderator');

        $this->regularUser = User::factory()->create();

        Cache::flush();
    }

    public function test_command_has_correct_metadata(): void
    {
        $this->assertEquals('slowmode', $this->command->name());
        $this->assertEquals('/slowmode [seconds|off]', $this->command->signature());
        $this->assertEquals('Enable, configure or disable slow mode', $this->command->description());
        $this->assertContains('slow', $this->command->aliases());
    }

    public function test_admin_can_enable_slow_mode(): void
    {
        Event::fake();

        $this->command->handle($this->admin, ['duration' => '10']);

        $this->assertDatabaseHas('chat_settings', ['key' => 'slow_mode_seconds', 'value' => '10']);
        $this->assertSame(10, app(ChatSettingsService::class)->slowModeSeconds());

        Event::assertDispatched(
            CommandFeedbackEvent::class,
            fn ($event) => str_contains($event->message, 'Slow mode enabled: 10 seconds'),
        );
        Event::assertDispatched(ChatSettingsUpdatedEvent::class);
    }

    public function test_moderator_can_enable_slow_mode(): void
    {
        $this->assertTrue($this->command->authorize($this->moderator));

        Event::fake();

        $this->command->handle($this->moderator, ['duration' => '30']);

        $this->assertDatabaseHas('chat_settings', ['key' => 'slow_mode_seconds', 'value' => '30']);
    }

    public function test_regular_user_cannot_enable_slow_mode(): void
    {
        $this->assertFalse($this->command->authorize($this->regularUser));
    }

    public function test_slow_mode_can_be_turned_off(): void
    {
        app(ChatSettingsService::class)->update(['slow_mode_seconds' => 15]);

        Event::fake();

        $this->command->handle($this->admin, ['duration' => 'off']);

        $this->assertDatabaseHas('chat_settings', ['key' => 'slow_mode_seconds', 'value' => '0']);
        $this->assertSame(0, app(ChatSettingsService::class)->slowModeSeconds());

        Event::assertDispatched(
            CommandFeedbackEvent::class,
            fn ($event) => str_contains($event->message, 'Slow mode disabled'),
        );
    }

    public function test_zero_also_turns_slow_mode_off(): void
    {
        app(ChatSettingsService::class)->update(['slow_mode_seconds' => 10]);

        Event::fake();

        $this->command->handle($this->admin, ['duration' => '0']);

        $this->assertDatabaseHas('chat_settings', ['key' => 'slow_mode_seconds', 'value' => '0']);
    }

    public function test_status_is_reported_when_no_duration_is_given(): void
    {
        app(ChatSettingsService::class)->update(['slow_mode_seconds' => 20]);

        Event::fake();

        $this->command->handle($this->admin, ['duration' => null]);

        Event::assertDispatched(
            CommandFeedbackEvent::class,
            fn ($event) => str_contains($event->message, 'Slow mode is set to 20 seconds'),
        );
        Event::assertNotDispatched(ChatSettingsUpdatedEvent::class);
    }

    public function test_status_is_reported_when_disabled(): void
    {
        Event::fake();

        $this->command->handle($this->admin, ['duration' => null]);

        Event::assertDispatched(
            CommandFeedbackEvent::class,
            fn ($event) => str_contains($event->message, 'Slow mode is disabled'),
        );
    }

    public function test_invalid_duration_is_rejected(): void
    {
        Event::fake();

        $this->command->handle($this->admin, ['duration' => 'invalid']);

        Event::assertDispatched(
            CommandFeedbackEvent::class,
            fn ($event) => str_contains($event->message, 'between 1 and 300'),
        );

        $this->assertDatabaseMissing('chat_settings', ['key' => 'slow_mode_seconds']);
    }

    public function test_duration_over_the_limit_is_rejected(): void
    {
        Event::fake();

        $this->command->handle($this->admin, ['duration' => '301']);

        Event::assertDispatched(
            CommandFeedbackEvent::class,
            fn ($event) => str_contains($event->message, 'between 1 and 300'),
        );

        $this->assertDatabaseMissing('chat_settings', ['key' => 'slow_mode_seconds']);
    }

    public function test_existing_setting_is_updated_in_place(): void
    {
        $setting = ChatSetting::create(['key' => 'slow_mode_seconds', 'value' => '5']);

        Event::fake();

        $this->command->handle($this->admin, ['duration' => '15']);

        $this->assertDatabaseCount('chat_settings', 1);
        $this->assertSame('15', $setting->refresh()->value);
    }

    public function test_command_provides_examples(): void
    {
        $examples = $this->command->examples();

        $this->assertArrayHasKey('/slowmode', $examples);
        $this->assertArrayHasKey('/slowmode 10', $examples);
        $this->assertArrayHasKey('/slowmode off', $examples);
    }
}
