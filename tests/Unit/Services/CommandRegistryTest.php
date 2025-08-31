<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\CommandRegistry;
use App\Models\User;
use App\Console\Commands\Chat\HelpCommand;
use App\Console\Commands\Chat\TimeoutCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class CommandRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected CommandRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
        
        $this->registry = new CommandRegistry();
    }

    public function test_it_discovers_commands()
    {
        $commands = $this->registry->all();
        
        $this->assertIsArray($commands);
        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('timeout', $commands);
        $this->assertArrayHasKey('slowmode', $commands);
    }

    public function test_it_can_get_command_by_name()
    {
        $command = $this->registry->get('help');
        
        $this->assertInstanceOf(HelpCommand::class, $command);
        $this->assertEquals('help', $command->name());
    }

    public function test_it_can_get_command_by_alias()
    {
        $command = $this->registry->get('to'); // alias for timeout
        
        $this->assertInstanceOf(TimeoutCommand::class, $command);
        $this->assertEquals('timeout', $command->name());
    }

    public function test_it_returns_null_for_unknown_command()
    {
        $command = $this->registry->get('nonexistent');
        
        $this->assertNull($command);
    }

    public function test_it_filters_commands_by_user_permissions()
    {
        $regularUser = User::factory()->create();
        $adminUser = User::factory()->create();
        
        // Give admin user the admin role
        $adminUser->assignRole('admin');
        
        $regularCommands = $this->registry->availableFor($regularUser);
        $adminCommands = $this->registry->availableFor($adminUser);
        
        // Regular user should have fewer commands
        $this->assertArrayHasKey('help', $regularCommands);
        $this->assertArrayNotHasKey('timeout', $regularCommands);
        
        // Admin should have all commands
        $this->assertArrayHasKey('help', $adminCommands);
        $this->assertArrayHasKey('timeout', $adminCommands);
        $this->assertArrayHasKey('badge', $adminCommands);
    }

    public function test_it_can_search_commands()
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        
        // Search for 'time'
        $results = $this->registry->search('time', $user);
        
        $this->assertArrayHasKey('timeout', $results);
        $this->assertArrayNotHasKey('help', $results);
        
        // Search for 'help'
        $results = $this->registry->search('help', $user);
        
        $this->assertArrayHasKey('help', $results);
    }

    public function test_it_finds_command_by_input()
    {
        $command = $this->registry->findByInput('/help');
        $this->assertInstanceOf(HelpCommand::class, $command);
        
        $command = $this->registry->findByInput('/timeout user 5m');
        $this->assertInstanceOf(TimeoutCommand::class, $command);
        
        $command = $this->registry->findByInput('!help');
        $this->assertInstanceOf(HelpCommand::class, $command);
        
        $command = $this->registry->findByInput('help'); // without prefix
        $this->assertInstanceOf(HelpCommand::class, $command);
    }

    public function test_it_generates_suggestions()
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        
        // Get suggestions for 'ti'
        $suggestions = $this->registry->getSuggestions('ti', $user);
        
        $this->assertIsArray($suggestions);
        $this->assertCount(1, $suggestions);
        $this->assertEquals('timeout', $suggestions[0]['name']);
        
        // Get all suggestions
        $suggestions = $this->registry->getSuggestions('', $user);
        
        $this->assertIsArray($suggestions);
        $this->assertGreaterThan(5, count($suggestions));
    }

    public function test_it_can_register_command_manually()
    {
        $mockCommand = new class extends \App\Console\Commands\Chat\AbstractChatCommand {
            protected string $name = 'test';
            protected string $signature = '/test';
            protected string $description = 'Test command';
            
            protected function execute(\App\Models\User $user, array $parameters): void
            {
                // Test implementation
            }
        };
        
        $this->registry->register($mockCommand);
        
        $this->assertTrue($this->registry->has('test'));
        $command = $this->registry->get('test');
        $this->assertEquals('test', $command->name());
    }

    public function test_it_caches_discovered_commands()
    {
        // First call should discover and cache
        $commands1 = $this->registry->all();
        
        // Second call should use cache
        $commands2 = $this->registry->all();
        
        $this->assertEquals($commands1, $commands2);
        
        // Clear cache
        $this->registry->clearCache();
        
        // Should rediscover after cache clear
        $commands3 = $this->registry->all();
        $this->assertArrayHasKey('help', $commands3);
    }
}