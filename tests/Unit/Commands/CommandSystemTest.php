<?php

namespace Tests\Unit\Commands;

use Tests\TestCase;
use App\Console\Commands\Chat\TimeoutCommand;
use App\Console\Commands\Chat\SlowModeCommand;
use App\Console\Commands\Chat\BadgeCommand;
use App\Console\Commands\Chat\HelpCommand;
use App\Services\CommandRegistry;
use App\Events\CommandFeedbackEvent;
use Illuminate\Support\Facades\Event;

class CommandSystemTest extends TestCase
{
    /**
     * Test that all commands implement the interface correctly.
     */
    public function test_all_commands_implement_interface_correctly()
    {
        $commands = [
            TimeoutCommand::class,
            SlowModeCommand::class,
            BadgeCommand::class,
            HelpCommand::class,
        ];

        foreach ($commands as $commandClass) {
            $command = new $commandClass();
            
            // Check all required methods exist and return proper types
            $this->assertIsString($command->name());
            $this->assertIsString($command->signature());
            $this->assertIsString($command->description());
            $this->assertIsArray($command->aliases());
            $this->assertIsArray($command->parameters());
            $this->assertIsArray($command->rules());
            
            // Check signature format
            $this->assertStringStartsWith('/', $command->signature());
        }
    }

    /**
     * Test command discovery and registration.
     */
    public function test_command_registry_can_discover_commands()
    {
        $registry = new CommandRegistry();
        
        // Clear cache to ensure fresh discovery
        $registry->clearCache();
        
        $commands = $registry->all();
        
        $this->assertIsArray($commands);
        
        // Check that our core commands are discovered
        $expectedCommands = ['help', 'timeout', 'slowmode', 'badge', 'delete', 'broadcast', 'nuke'];
        
        foreach ($expectedCommands as $expectedCommand) {
            $this->assertArrayHasKey($expectedCommand, $commands, "Command '$expectedCommand' was not discovered");
        }
    }

    /**
     * Test command aliases work correctly.
     */
    public function test_command_aliases_resolve_correctly()
    {
        $registry = new CommandRegistry();
        
        // Test timeout aliases
        $command = $registry->get('to'); // alias for timeout
        $this->assertNotNull($command);
        $this->assertEquals('timeout', $command->name());
        
        $command = $registry->get('mute'); // another alias for timeout
        $this->assertNotNull($command);
        $this->assertEquals('timeout', $command->name());
        
        // Test help aliases
        $command = $registry->get('h'); // alias for help
        $this->assertNotNull($command);
        $this->assertEquals('help', $command->name());
    }


    /**
     * Test command feedback event structure.
     */
    public function test_command_feedback_event()
    {
        Event::fake();
        
        $user = new \App\Models\User();
        $user->id = 1;
        $user->name = 'TestUser';
        
        $event = new CommandFeedbackEvent($user, 'Test message', 'success', ['extra' => 'data']);
        
        $this->assertEquals($user, $event->user);
        $this->assertEquals('Test message', $event->message);
        $this->assertEquals('success', $event->type);
        $this->assertEquals(['extra' => 'data'], $event->data);
        
        // Test broadcasting channel
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertEquals('private-command-feedback.1', $channels[0]->name);
    }

    /**
     * Test command suggestions generation.
     */
    public function test_command_suggestions()
    {
        $registry = new CommandRegistry();
        
        // Mock user with permissions
        $user = $this->createMock(\App\Models\User::class);
        $user->method('can')->willReturn(true);
        $user->method('hasRole')->willReturn(true);
        $user->method('hasPermission')->willReturn(true);
        
        // Test partial matching
        $suggestions = $registry->getSuggestions('ti', $user);
        
        $this->assertIsArray($suggestions);
        $this->assertNotEmpty($suggestions);
        
        // Should find timeout command
        $found = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['name'] === 'timeout') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Timeout command not found in suggestions');
    }

    /**
     * Test command examples are provided.
     */
    public function test_commands_provide_examples()
    {
        $commandClasses = [
            TimeoutCommand::class,
            SlowModeCommand::class,
            BadgeCommand::class,
            HelpCommand::class,
        ];

        foreach ($commandClasses as $commandClass) {
            $command = new $commandClass();
            
            if (method_exists($command, 'examples')) {
                $examples = $command->examples();
                $this->assertIsArray($examples);
                $this->assertNotEmpty($examples, "Command {$command->name()} should provide examples");
                
                // Check examples format
                foreach ($examples as $example => $description) {
                    $this->assertIsString($example);
                    $this->assertIsString($description);
                    $this->assertStringStartsWith('/', $example);
                }
            }
        }
    }

    /**
     * Test command validation rules generation.
     */
    public function test_command_validation_rules()
    {
        $command = new BadgeCommand();
        $rules = $command->rules();
        
        $this->assertIsArray($rules);
        $this->assertArrayHasKey('action', $rules);
        $this->assertArrayHasKey('username', $rules);
        $this->assertArrayHasKey('badge_type', $rules);
        
        // Check rule format
        $this->assertStringContainsString('required', $rules['action']);
        $this->assertStringContainsString('in:grant,revoke', $rules['action']);
    }

    /**
     * Test finding command by raw input.
     */
    public function test_find_command_by_input()
    {
        $registry = new CommandRegistry();
        
        $testCases = [
            '/help' => 'help',
            '/timeout user 5m' => 'timeout',
            '!help' => 'help',
            '/to user 5m' => 'timeout',
            '/slowmode 10' => 'slowmode',
            '/badge grant user vip' => 'badge',
        ];
        
        foreach ($testCases as $input => $expectedCommand) {
            $command = $registry->findByInput($input);
            $this->assertNotNull($command, "Command not found for input: $input");
            $this->assertEquals($expectedCommand, $command->name(), "Wrong command found for input: $input");
        }
    }
}