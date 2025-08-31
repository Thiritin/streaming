<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\ChatSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SlowModeCommand extends AbstractChatCommand
{
    protected string $name = 'slowmode';
    protected array $aliases = ['slow'];
    protected string $description = 'Enable or configure slow mode for chat';
    protected string $signature = '/slowmode [seconds|off]';

    protected array $parameters = [
        'duration' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Seconds between messages or "off" to disable',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->hasPermission('chat.moderate') || 
               $user->hasRole('admin') ||
               $user->hasRole('moderator');
    }

    protected function execute(User $user, array $parameters): void
    {
        $duration = $parameters['duration'] ?? null;

        // Get current slow mode setting
        $currentSetting = ChatSetting::where('key', 'slow_mode_seconds')->first();
        $currentValue = $currentSetting ? (int) $currentSetting->value : 0;

        // If no parameter provided, show current status
        if ($duration === null) {
            if ($currentValue > 0) {
                $this->feedback($user, "Slow mode is currently set to {$currentValue} seconds.", 'info');
            } else {
                $this->feedback($user, 'Slow mode is currently disabled.', 'info');
            }
            return;
        }

        // Handle turning off slow mode
        if (strtolower($duration) === 'off' || $duration === '0') {
            if ($currentSetting) {
                $currentSetting->update(['value' => '0']);
            }
            
            Cache::forget('chat.slow_mode');
            
            $this->feedback($user, 'Slow mode has been disabled.', 'success');
            $this->broadcastSystemMessage('Slow mode has been disabled', 'info');
            
            Log::info('Slow mode disabled', [
                'moderator_id' => $user->id,
            ]);
            return;
        }

        // Validate duration is a positive integer
        if (!is_numeric($duration) || $duration < 1) {
            $this->feedback($user, 'Duration must be a positive number of seconds or "off".', 'error');
            return;
        }

        $seconds = (int) $duration;

        // Validate reasonable limits (1 second to 5 minutes)
        if ($seconds < 1 || $seconds > 300) {
            $this->feedback($user, 'Duration must be between 1 and 300 seconds.', 'error');
            return;
        }

        // Update or create setting
        ChatSetting::updateOrCreate(
            ['key' => 'slow_mode_seconds'],
            ['value' => (string) $seconds]
        );

        // Clear cache to apply immediately
        Cache::forget('chat.slow_mode');
        Cache::put('chat.slow_mode', $seconds, now()->addHours(24));

        $this->feedback($user, "Slow mode enabled: {$seconds} seconds between messages.", 'success');
        $this->broadcastSystemMessage("Slow mode enabled: {$seconds} seconds between messages", 'warning');

        Log::info('Slow mode enabled', [
            'moderator_id' => $user->id,
            'seconds' => $seconds,
        ]);
    }

    public function examples(): array
    {
        return [
            '/slowmode' => 'Check current slow mode status',
            '/slowmode 10' => 'Enable 10 second slow mode',
            '/slowmode 30' => 'Enable 30 second slow mode',
            '/slowmode off' => 'Disable slow mode',
            '/slow 5' => 'Using alias to enable 5 second slow mode',
        ];
    }
}