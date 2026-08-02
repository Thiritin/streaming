<?php

namespace App\Services\Chat;

use App\Events\Chat\Broadcasts\ChatSettingsUpdatedEvent;
use App\Models\ChatSetting;
use App\Support\Chat\Broadcast;

/**
 * Per-source chat modes (slow mode, emote only, sponsors only) with a global fallback.
 */
class ChatSettingsService
{
    public const KEYS = [
        'slow_mode_seconds' => 'int',
        'emote_only' => 'bool',
        'sponsors_only' => 'bool',
    ];

    /**
     * @return array<string, mixed>
     */
    public function all(?int $sourceId = null): array
    {
        return [
            'slow_mode_seconds' => (int) ChatSetting::getValue('slow_mode_seconds', (string) config('chat.default.slowModeSeconds', 0), $sourceId),
            'emote_only' => filter_var(ChatSetting::getValue('emote_only', '0', $sourceId), FILTER_VALIDATE_BOOL),
            'sponsors_only' => filter_var(ChatSetting::getValue('sponsors_only', '0', $sourceId), FILTER_VALIDATE_BOOL),
            'max_message_length' => (int) config('chat.default.maxMessageLength', 500),
            'max_tries' => (int) config('chat.default.maxTries', 8),
            'rate_decay' => (int) config('chat.default.rateDecay', 30),
        ];
    }

    public function slowModeSeconds(?int $sourceId = null): int
    {
        return (int) ChatSetting::getValue('slow_mode_seconds', (string) config('chat.default.slowModeSeconds', 0), $sourceId);
    }

    public function emoteOnly(?int $sourceId = null): bool
    {
        return filter_var(ChatSetting::getValue('emote_only', '0', $sourceId), FILTER_VALIDATE_BOOL);
    }

    public function sponsorsOnly(?int $sourceId = null): bool
    {
        return filter_var(ChatSetting::getValue('sponsors_only', '0', $sourceId), FILTER_VALIDATE_BOOL);
    }

    /**
     * Apply a partial settings update and broadcast the result.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the full settings after the update
     */
    public function update(array $changes, ?int $sourceId = null): array
    {
        foreach ($changes as $key => $value) {
            if (! array_key_exists($key, self::KEYS)) {
                continue;
            }

            $stored = match (self::KEYS[$key]) {
                'int' => (string) max(0, (int) $value),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
                default => (string) $value,
            };

            ChatSetting::setValue($key, $stored, null, $sourceId);
        }

        $settings = $this->all($sourceId);

        Broadcast::send(new ChatSettingsUpdatedEvent($settings, $sourceId));

        return $settings;
    }
}
