<?php

namespace App\Support;

use App\Models\Show;
use App\Models\TelegramLinkCode;
use App\Models\User;

/**
 * Everything the notification settings panel needs, wherever it is rendered.
 *
 * The panel is one component and appears in two places - the settings page, and a
 * dialog off the bell in the archive - so the payload is assembled once here rather
 * than in each controller. Null for a guest and for an installation with the feature
 * off, which is what keeps the bell off the page rather than on it and inert.
 */
final class ViewerNotificationProps
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(?User $user): ?array
    {
        if (! $user || ! Features::enabled('notifications')) {
            return null;
        }

        return [
            'email' => $user->email,
            'telegram' => self::telegram($user),
            'channels' => [
                'available' => $user->availableNotificationChannels(),
                // A null preference means every transport this account has, which is
                // what somebody who never opened this panel meant. It shows as both
                // boxes ticked.
                'selected' => $user->notificationChannels(),
            ],
            // Each category is a sentence with the scope as an inline control in the
            // middle of it, so what is being chosen reads as English rather than as a
            // label and a box that have to be held in the head together.
            'categories' => array_map(fn (string $key) => [
                'key' => $key,
                'before' => NotificationCategories::all()[$key]['before'],
                'after' => NotificationCategories::all()[$key]['after'],
                'label' => NotificationCategories::label($key),
                'scope' => $user->notificationScope($key),
            ], NotificationCategories::keys()),
            'scopeOptions' => NotificationScope::options(),
            'followedShows' => $user->subscribedShows()
                ->orderBy('scheduled_start')
                ->get()
                ->map(fn (Show $show) => [
                    'id' => $show->id,
                    'title' => $show->title,
                    'slug' => $show->slug,
                    'status' => $show->status,
                    'scheduled_start' => $show->scheduled_start?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The Telegram half: connected, or the two ways of connecting.
     *
     * The code is minted here rather than behind a button, because the connect link is
     * a deep link that has to carry it - Telegram opens the bot with Start already
     * holding the code, so nothing is pasted. An existing unused code is reused, so
     * rendering this panel repeatedly does not leave a trail of valid ones.
     *
     * @return array<string, mixed>
     */
    private static function telegram(User $user): array
    {
        if ($user->hasTelegramLink()) {
            return [
                'linked' => true,
                'username' => $user->telegram_username,
                'linked_at' => $user->telegram_linked_at?->toIso8601String(),
                'bot' => TelegramSettings::username(),
                'code' => null,
                'connect_url' => null,
            ];
        }

        // Nothing to connect to without a bot, and a code would only be a puzzle.
        if (! TelegramSettings::configured()) {
            return [
                'linked' => false,
                'username' => null,
                'linked_at' => null,
                'bot' => null,
                'code' => null,
                'connect_url' => null,
            ];
        }

        $code = TelegramLinkCode::ensureForViewer($user);

        return [
            'linked' => false,
            'username' => null,
            'linked_at' => null,
            'bot' => TelegramSettings::resolveUsername(),
            'code' => $code->code,
            // Null when nothing knows the bot's @name, and the panel falls back to the
            // code with instructions rather than offering a link into nowhere.
            'connect_url' => TelegramSettings::connectUrl($code->code),
        ];
    }
}
