<?php

namespace App\Support\Manage;

use Inertia\Inertia;
use Inertia\SessionKey;

/**
 * Flash payload for the toast host in ManageLayout.
 *
 * Uses Inertia's own flash mechanism (Inertia::flash), so the toast arrives as a
 * top-level `flash` prop, is excluded from the browser's history state, and survives the
 * redirect an action performs. Without that, navigating back would replay old toasts.
 *
 * Titles and bodies deliberately match the notifications the Filament panel sent, so the
 * parity tests can assert on them verbatim.
 */
final class Toast
{
    /**
     * @return array{tone: string, title: string, body: string|null}
     */
    public static function make(string $tone, string $title, ?string $body = null): array
    {
        return ['tone' => $tone, 'title' => $title, 'body' => $body];
    }

    /**
     * @return array{tone: string, title: string, body: string|null}
     */
    public static function success(string $title, ?string $body = null): array
    {
        return self::make('success', $title, $body);
    }

    /**
     * @return array{tone: string, title: string, body: string|null}
     */
    public static function warning(string $title, ?string $body = null): array
    {
        return self::make('warning', $title, $body);
    }

    /**
     * @return array{tone: string, title: string, body: string|null}
     */
    public static function danger(string $title, ?string $body = null): array
    {
        return self::make('danger', $title, $body);
    }

    /**
     * @param  array{tone: string, title: string, body: string|null}  $toast
     */
    public static function flash(array $toast): void
    {
        self::put('toast', $toast);
    }

    /**
     * Writes into Inertia's own flash bag, so the payload arrives as the top-level `flash`
     * prop and stays out of the browser's history state (a back navigation must not replay
     * a toast).
     *
     * It is written for the *next* request rather than through Inertia::flash(), which uses
     * session()->now(). On inertia-laravel 2.0.19 a now() value cannot survive the redirect
     * an action performs: the key sits in `_flash.old`, and ageFlashData() forgets old keys
     * on save before the middleware's re-flash can take effect. Since every manage action
     * redirects, flashing forward is both correct and simpler.
     *
     * Revisit when the package flashes forward itself.
     */
    public static function put(string $key, mixed $value): void
    {
        session()->flash(SessionKey::FlashData->value, [
            ...Inertia::getFlashed(),
            $key => $value,
        ]);
    }

    public static function flashSuccess(string $title, ?string $body = null): void
    {
        self::flash(self::success($title, $body));
    }

    public static function flashWarning(string $title, ?string $body = null): void
    {
        self::flash(self::warning($title, $body));
    }

    public static function flashDanger(string $title, ?string $body = null): void
    {
        self::flash(self::danger($title, $body));
    }
}
