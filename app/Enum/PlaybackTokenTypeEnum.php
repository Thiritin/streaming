<?php

namespace App\Enum;

enum PlaybackTokenTypeEnum: string
{
    /** Short-lived token for a signed-in attendee watching in the web player. */
    case VIEWER = 'viewer';

    /** Long-lived key baked into an external embed (VRChat world, kiosk display). */
    case EMBED = 'embed';

    public function label(): string
    {
        return match ($this) {
            self::VIEWER => 'Viewer',
            self::EMBED => 'Embed',
        };
    }

    /**
     * Each type is signed with its own secret, so leaking the viewer secret
     * cannot be used to mint long-lived embed keys.
     */
    public function secretConfigKey(): string
    {
        return match ($this) {
            self::VIEWER => 'stream.token.viewer_secret',
            self::EMBED => 'stream.token.embed_secret',
        };
    }

    /** Viewer tokens must expire; embed keys are stable for a baked-in URL. */
    public function requiresExpiry(): bool
    {
        return $this === self::VIEWER;
    }
}
