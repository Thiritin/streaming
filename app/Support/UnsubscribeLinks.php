<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The links in the footer of every email.
 *
 * Signed rather than authenticated: mail is read on a phone that is not signed in, and
 * an unsubscribe link that first asks somebody to log in is an unsubscribe link that
 * does not work. The signature covers the account and the category, so a link can only
 * ever switch off the thing it was written for, for the person it was sent to.
 *
 * Never expiring, deliberately. A message sits in an inbox for months and the promise
 * on it has to keep being true; a rotated APP_KEY invalidates them, which is the same
 * blast radius as every other signed URL in the app.
 */
final class UnsubscribeLinks
{
    public const ALL = 'all';

    /**
     * Everything the mail templates need, for one category.
     *
     * @return array{label: string, category: string, all: string}
     */
    public static function forUser(User $user, string $category): array
    {
        return [
            'label' => mb_strtolower(NotificationCategories::label($category)),
            'category' => self::category($user, $category),
            'all' => self::everything($user),
        ];
    }

    public static function category(User $user, string $category): string
    {
        return URL::signedRoute('notifications.unsubscribe', [
            'user' => $user->getKey(),
            'category' => $category,
        ]);
    }

    public static function everything(User $user): string
    {
        return self::category($user, self::ALL);
    }
}
