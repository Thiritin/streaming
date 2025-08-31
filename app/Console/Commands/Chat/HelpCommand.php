<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Services\CommandRegistry;

class HelpCommand extends AbstractChatCommand
{
    protected string $name = 'help';
    protected array $aliases = ['h', 'commands'];
    protected string $description = 'Show available commands and their usage';
    protected string $signature = '/help [command]';

    protected array $parameters = [
        'command' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Specific command to get help for',
        ],
    ];

    protected ?CommandRegistry $registry = null;

    protected function getRegistry(): CommandRegistry
    {
        if (!$this->registry) {
            $this->registry = app(CommandRegistry::class);
        }
        return $this->registry;
    }

    public function authorize(User $user): bool
    {
        return true; // Everyone can use help
    }

    protected function execute(User $user, array $parameters): void
    {
        $specificCommand = $parameters['command'] ?? null;

        if ($specificCommand) {
            $this->showCommandHelp($user, $specificCommand);
        } else {
            $this->showAllCommands($user);
        }
    }

    private function showCommandHelp(User $user, string $commandName): void
    {
        $command = $this->getRegistry()->get($commandName);

        if (!$command) {
            $this->feedback($user, "Command '/{$commandName}' not found.", 'error');
            return;
        }

        if (!$command->authorize($user)) {
            $this->feedback($user, "You don't have permission to use '/{$commandName}'.", 'error');
            return;
        }

        $info = $command->toArray();
        $examples = method_exists($command, 'examples') ? $command->examples() : [];

        // Build help message
        $message = "**Command:** /{$info['name']}\n";
        $message .= "**Description:** {$info['description']}\n";
        $message .= "**Usage:** {$info['signature']}\n";

        if (!empty($info['aliases'])) {
            $aliases = array_map(fn($a) => "/{$a}", $info['aliases']);
            $message .= "**Aliases:** " . implode(', ', $aliases) . "\n";
        }

        if (!empty($examples)) {
            $message .= "\n**Examples:**\n";
            foreach ($examples as $example => $description) {
                $message .= "• `{$example}` - {$description}\n";
            }
        }

        $this->feedback($user, $message, 'info', ['format' => 'markdown']);
    }

    private function showAllCommands(User $user): void
    {
        $availableCommands = $this->getRegistry()->availableFor($user);

        if (empty($availableCommands)) {
            $this->feedback($user, 'No commands available for your permission level.', 'info');
            return;
        }

        // Group commands by category
        $grouped = [];
        foreach ($availableCommands as $name => $metadata) {
            $category = $this->getCommandCategory($name);
            $grouped[$category][] = $metadata;
        }

        // Build help message
        $message = "**Available Commands:**\n\n";

        foreach ($grouped as $category => $commands) {
            $message .= "**{$category}:**\n";
            foreach ($commands as $cmd) {
                $message .= "• `/{$cmd['name']}` - {$cmd['description']}\n";
            }
            $message .= "\n";
        }

        $message .= "_Use `/help <command>` for detailed information about a specific command._";

        $this->feedback($user, $message, 'info', ['format' => 'markdown']);
    }

    private function getCommandCategory(string $commandName): string
    {
        return match($commandName) {
            'timeout', 'slowmode', 'delete', 'nuke' => 'Moderation',
            'badge' => 'User Management',
            'broadcast' => 'Communication',
            'help' => 'Information',
            default => 'General',
        };
    }

    public function examples(): array
    {
        return [
            '/help' => 'Show all available commands',
            '/help timeout' => 'Get detailed help for the timeout command',
            '/commands' => 'Using alias to show all commands',
        ];
    }
}