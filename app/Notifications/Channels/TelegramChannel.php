<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivery into a viewer's own conversation with the bot.
 *
 * Nothing here touches `telegram_chats`: that table is the operator chats, where a
 * message can carry buttons that start and end shows. A viewer's chat is a column on
 * their account and gets link buttons and nothing else.
 */
class TelegramChannel
{
    public function __construct(private readonly TelegramClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $chatId = method_exists($notifiable, 'routeNotificationForTelegram')
            ? $notifiable->routeNotificationForTelegram()
            : null;

        if (! $chatId || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $message = $notification->toTelegram($notifiable);

        $error = $this->client->sendToChat($chatId, $message->text, $message->photoUrl, $message->keyboard);

        if ($error === null) {
            return;
        }

        // Blocked, deleted, deactivated: the id is not coming back, and leaving it on
        // the account means retrying it for every recording published from here on.
        if ($this->client->isFatal($error) && $notifiable instanceof User) {
            Log::info('Unlinking a Telegram account Telegram will not deliver to', [
                'user_id' => $notifiable->id,
                'reason' => $error,
            ]);

            $notifiable->forceFill([
                'telegram_chat_id' => null,
                'telegram_username' => null,
                'telegram_linked_at' => null,
            ])->save();
        }

        throw new RuntimeException($error);
    }
}
