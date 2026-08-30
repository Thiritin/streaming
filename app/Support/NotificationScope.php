<?php

namespace App\Support;

/**
 * How wide a notification category is drawn for one viewer.
 *
 * Three answers rather than a switch, because "every recording published" and "the
 * shows I followed" are different subscriptions to the same event, and a convention
 * publishes far more than any one person asked to hear about.
 *
 * `subscribed` is the default on both categories. Following a show is then the only
 * thing a viewer has to find: press the bell, get told. `any` is the opt-in for
 * somebody who wants the whole programme, and is what the bell in the archive sets.
 */
final class NotificationScope
{
    public const OFF = 'off';

    public const SUBSCRIBED = 'subscribed';

    public const ANY = 'any';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::OFF, self::SUBSCRIBED, self::ANY];
    }

    /**
     * The word that goes in the middle of the sentence on the settings page.
     *
     * Off is not among them: the checkbox beside the sentence is what switches a
     * category off, and "tell me when no show goes on air" was a sentence nobody
     * should have to parse.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::ANY => 'any',
            self::SUBSCRIBED => 'a followed',
        ];
    }

    public static function valid(?string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * Whether this scope covers an event, given whether the viewer followed the show
     * it belongs to.
     */
    public static function covers(?string $scope, bool $followed): bool
    {
        return match ($scope) {
            self::ANY => true,
            self::SUBSCRIBED => $followed,
            default => false,
        };
    }
}
