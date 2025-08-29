<?php

namespace App\Jobs\ChatCommands;

use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class AbstractChatCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(
        public readonly User $user,
        public readonly Message $message,
        public readonly string $command
    ) {
    }
    
    /**
     * Get command metadata for display
     */
    abstract public static function getMeta(): array;
    
    /**
     * Check if user can execute this command
     */
    abstract public function canExecute(): bool;
    
    /**
     * Execute the command logic
     */
    abstract protected function execute(): void;
    
    /**
     * Handle the job
     */
    public function handle(): void
    {
        if (!$this->canExecute()) {
            return;
        }
        
        $this->execute();
    }
    
    /**
     * Parse command arguments with support for quoted strings
     */
    protected function parseArguments(): array
    {
        // Remove the command prefix (! or /)
        $commandString = ltrim($this->command, '!/');
        
        // Match quoted strings and unquoted words
        preg_match_all('/"([^"]+)"|(\S+)/', $commandString, $matches);
        
        $args = [];
        foreach ($matches[0] as $match) {
            // Remove surrounding quotes if present
            $args[] = trim($match, '"');
        }
        
        // Remove the command name itself
        array_shift($args);
        
        return $args;
    }
    
    /**
     * Get command name from the command string
     */
    protected function getCommandName(): string
    {
        $trimmed = ltrim($this->command, '!/');
        $parts = explode(' ', $trimmed);
        return $parts[0] ?? '';
    }
}