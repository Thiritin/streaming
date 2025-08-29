<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\Commands\SlowModeDisabled;
use App\Events\Chat\Commands\SlowModeEnabled;

class SlowModeJob extends AbstractChatCommand
{
    public static function getMeta(): array
    {
        return [
            'name' => 'slowmode',
            'description' => 'Enable or disable slow mode for chat',
            'syntax' => '/slowmode "seconds"',
            'parameters' => [
                ['name' => 'seconds', 'description' => 'Delay in seconds (0 or "off" to disable)', 'required' => true],
            ],
            'permission' => 'chat.commands.slowmode',
            'aliases' => ['slow'],
        ];
    }
    
    public function canExecute(): bool
    {
        return $this->user->can('chat.commands.slowmode');
    }
    
    protected function execute(): void
    {
        $args = $this->parseArguments();
        
        if (count($args) < 1) {
            return;
        }
        
        $value = $args[0];
        
        if ($value === 'off' || $value === '0' || $value === 0) {
            event(new SlowModeDisabled());
            return;
        }
        
        if (is_numeric($value) && $value < 500) {
            event(new SlowModeEnabled($value));
        }
    }
}
