<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Events\CommandFeedbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class CommandControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
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
        
        $this->user = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_execute_command_requires_authentication()
    {
        $response = $this->postJson('/api/command/execute', [
            'command' => '/help'
        ]);
        
        $response->assertStatus(401);
    }

    public function test_execute_help_command_as_regular_user()
    {
        Event::fake();
        
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/help'
            ]);
        
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'command' => 'help'
            ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class);
    }

    public function test_execute_admin_command_as_regular_user_fails()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/timeout testuser 5m'
            ]);
        
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'You do not have permission to use this command.'
            ]);
    }

    public function test_execute_admin_command_as_admin_succeeds()
    {
        Event::fake();
        
        $targetUser = User::factory()->create(['name' => 'testuser']);
        
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/timeout testuser 5m Testing'
            ]);
        
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'command' => 'timeout'
            ]);
        
        Event::assertDispatched(CommandFeedbackEvent::class);
    }

    public function test_execute_unknown_command_returns_error()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/unknowncommand'
            ]);
        
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => 'Command not found. Type /help for available commands.'
            ]);
    }

    public function test_command_rate_limiting()
    {
        // Clear rate limiter
        RateLimiter::clear('command-execute:' . $this->user->id);
        
        // Make 10 requests (the limit)
        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/command/execute', [
                    'command' => '/help'
                ]);
            
            $response->assertStatus(200);
        }
        
        // 11th request should be rate limited
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/help'
            ]);
        
        $response->assertStatus(429)
            ->assertJsonStructure([
                'success',
                'error',
                'retry_after'
            ]);
    }

    public function test_get_command_suggestions()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/command/suggestions?query=ti');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'suggestions' => [
                    '*' => [
                        'name',
                        'signature',
                        'description',
                        'aliases'
                    ]
                ]
            ]);
        
        $suggestions = $response->json('suggestions');
        $this->assertCount(1, $suggestions);
        $this->assertEquals('timeout', $suggestions[0]['name']);
    }

    public function test_list_available_commands()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/command/list');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'commands',
                'grouped'
            ]);
        
        $commands = $response->json('commands');
        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayNotHasKey('timeout', $commands); // Regular user shouldn't see admin commands
    }

    public function test_search_commands()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/command/search?query=slow');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'results'
            ]);
        
        $results = $response->json('results');
        $this->assertArrayHasKey('slowmode', $results);
    }

    public function test_get_command_help()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/command/help?command=help');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'command' => [
                    'name',
                    'signature',
                    'description',
                    'aliases',
                    'parameters'
                ],
                'examples'
            ]);
    }

    public function test_get_help_for_admin_command_as_regular_user_fails()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/command/help?command=timeout');
        
        $response->assertStatus(403)
            ->assertJson([
                'error' => 'You do not have permission to view this command.'
            ]);
    }

    public function test_get_help_for_unknown_command_returns_error()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/command/help?command=nonexistent');
        
        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Command not found.'
            ]);
    }

    public function test_command_with_invalid_parameters_fails()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/timeout' // Missing required parameters
            ]);
        
        $response->assertStatus(500); // Command will fail during execution
    }

    public function test_command_execution_is_logged()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/command/execute', [
                'command' => '/help'
            ]);
        
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'App\Models\User',
            'subject_id' => $this->admin->id,
            'description' => 'Command executed'
        ]);
    }
}