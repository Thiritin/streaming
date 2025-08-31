<?php

namespace Tests\Unit\Commands;

use Tests\TestCase;
use App\Console\Commands\Chat\TimeoutCommand;
use App\Models\User;
use App\Models\Role;
use App\Models\Timeout;
use App\Events\CommandFeedbackEvent;
use App\Events\SystemMessageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;

class TimeoutCommandTest extends TestCase
{
    use RefreshDatabase;

    protected TimeoutCommand $command;
    protected User $admin;
    protected User $moderator;
    protected User $regularUser;
    protected User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->command = new TimeoutCommand();
        
        // Create roles
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'chat_color' => '#ff0000',
            'is_staff' => true,
        ]);
        
        $modRole = Role::create([
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
        $this->targetUser = User::factory()->create(['name' => 'targetuser']);
    }

    public function test_command_has_correct_metadata()
    {
        $this->assertEquals('timeout', $this->command->name());
        $this->assertEquals('/timeout <username> <duration> [reason]', $this->command->signature());
        $this->assertEquals('Timeout a user from sending messages', $this->command->description());
        $this->assertContains('to', $this->command->aliases());
        $this->assertContains('mute', $this->command->aliases());
    }

    public function test_admin_can_timeout_user()
    {
        Event::fake();
        
        $this->command->setRawInput('/timeout targetuser 5m Spamming');
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => '5m',
            'reason' => 'Spamming'
        ]);
        
        // Check timeout was created
        $this->assertDatabaseHas('timeouts', [
            'user_id' => $this->targetUser->id,
            'issued_by_user_id' => $this->admin->id,
            'reason' => 'Spamming',
        ]);
        
        $timeout = Timeout::where('user_id', $this->targetUser->id)->first();
        $this->assertTrue($timeout->expires_at->isFuture());
        $this->assertTrue($timeout->expires_at->diffInMinutes(now()) <= 5);
        
        // Check events were dispatched
        Event::assertDispatched(CommandFeedbackEvent::class, 2); // One for admin, one for target
        Event::assertDispatched(SystemMessageEvent::class);
    }

    public function test_moderator_can_timeout_user()
    {
        $this->assertTrue($this->command->authorize($this->moderator));
        
        Event::fake();
        
        $this->command->setRawInput('/timeout targetuser 1h');
        $this->command->handle($this->moderator, [
            'username' => 'targetuser',
            'duration' => '1h',
            'reason' => null
        ]);
        
        $this->assertDatabaseHas('timeouts', [
            'user_id' => $this->targetUser->id,
            'issued_by_user_id' => $this->moderator->id,
        ]);
    }

    public function test_regular_user_cannot_timeout()
    {
        $this->assertFalse($this->command->authorize($this->regularUser));
    }

    public function test_cannot_timeout_self()
    {
        Event::fake();
        
        $this->command->setRawInput('/timeout admin 5m');
        $this->command->handle($this->admin, [
            'username' => $this->admin->name,
            'duration' => '5m',
            'reason' => null
        ]);
        
        $this->assertDatabaseMissing('timeouts', [
            'user_id' => $this->admin->id,
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'cannot timeout yourself');
        });
    }

    public function test_cannot_timeout_admin_or_moderator()
    {
        Event::fake();
        
        $adminTarget = User::factory()->create();
        $adminTarget->assignRole('admin');
        
        $this->command->setRawInput('/timeout ' . $adminTarget->name . ' 5m');
        $this->command->handle($this->moderator, [
            'username' => $adminTarget->name,
            'duration' => '5m',
            'reason' => null
        ]);
        
        $this->assertDatabaseMissing('timeouts', [
            'user_id' => $adminTarget->id,
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'cannot timeout administrators');
        });
    }

    public function test_timeout_with_invalid_user()
    {
        Event::fake();
        
        $this->command->setRawInput('/timeout nonexistentuser 5m');
        $this->command->handle($this->admin, [
            'username' => 'nonexistentuser',
            'duration' => '5m',
            'reason' => null
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'not found');
        });
    }

    public function test_timeout_duration_parsing()
    {
        Event::fake();
        
        // Test seconds
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => '30s',
            'reason' => null
        ]);
        
        $timeout = Timeout::where('user_id', $this->targetUser->id)->first();
        $this->assertTrue($timeout->expires_at->diffInSeconds(now()) <= 30);
        $timeout->delete();
        
        // Test minutes
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => '10m',
            'reason' => null
        ]);
        
        $timeout = Timeout::where('user_id', $this->targetUser->id)->first();
        $this->assertTrue($timeout->expires_at->diffInMinutes(now()) <= 10);
        $timeout->delete();
        
        // Test hours
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => '2h',
            'reason' => null
        ]);
        
        $timeout = Timeout::where('user_id', $this->targetUser->id)->first();
        $this->assertTrue($timeout->expires_at->diffInHours(now()) <= 2);
        $timeout->delete();
        
        // Test days
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => '1d',
            'reason' => null
        ]);
        
        $timeout = Timeout::where('user_id', $this->targetUser->id)->first();
        $this->assertTrue($timeout->expires_at->diffInDays(now()) <= 1);
    }

    public function test_invalid_duration_format()
    {
        Event::fake();
        
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => 'invalid',
            'reason' => null
        ]);
        
        $this->assertDatabaseMissing('timeouts', [
            'user_id' => $this->targetUser->id,
        ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class, function ($event) {
            return str_contains($event->message, 'Invalid duration format');
        });
    }

    public function test_updating_existing_timeout()
    {
        // Create initial timeout
        $timeout = Timeout::create([
            'user_id' => $this->targetUser->id,
            'issued_by_user_id' => $this->moderator->id,
            'expires_at' => now()->addMinutes(5),
            'reason' => 'Initial reason',
        ]);
        
        Event::fake();
        
        // Update with new timeout
        $this->command->handle($this->admin, [
            'username' => 'targetuser',
            'duration' => '1h',
            'reason' => 'Updated reason'
        ]);
        
        // Should update existing timeout
        $this->assertDatabaseCount('timeouts', 1);
        
        $updatedTimeout = Timeout::find($timeout->id);
        $this->assertEquals($this->admin->id, $updatedTimeout->issued_by_user_id);
        $this->assertEquals('Updated reason', $updatedTimeout->reason);
        $this->assertTrue($updatedTimeout->expires_at->diffInMinutes(now()) > 30);
    }

    public function test_command_provides_examples()
    {
        $examples = $this->command->examples();
        
        $this->assertIsArray($examples);
        $this->assertArrayHasKey('/timeout JohnDoe 5m', $examples);
        $this->assertArrayHasKey('/timeout JohnDoe 1h Spamming', $examples);
    }
}