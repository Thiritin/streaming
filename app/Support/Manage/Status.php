<?php

namespace App\Support\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Enum\StreamStatusEnum;

/**
 * Single source of truth for how a domain state is presented: label, tone, glyph.
 *
 * The client never derives a colour from a raw value. It receives a tone name and
 * looks that up in the CSS token map, so the table, the badges and the status strip
 * cannot drift apart.
 *
 * Icon names are lucide names in kebab-case, resolved by ManageIcon.vue.
 */
final class Status
{
    public const LIVE = 'live';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const IDLE = 'idle';

    public const DANGER = 'danger';

    public const INFO = 'info';

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function make(string $label, string $tone, ?string $icon = null): array
    {
        return ['label' => $label, 'tone' => $tone, 'icon' => $icon];
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function show(?string $status): array
    {
        return match ($status) {
            'live' => self::make('Live', self::LIVE, 'signal'),
            'scheduled' => self::make('Scheduled', self::WARN, 'clock'),
            'ended' => self::make('Ended', self::IDLE, 'circle-check'),
            'cancelled' => self::make('Cancelled', self::DANGER, 'circle-x'),
            default => self::make((string) $status, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function source(SourceStatusEnum|string|null $status): array
    {
        $value = $status instanceof SourceStatusEnum ? $status->value : $status;

        return match ($value) {
            SourceStatusEnum::ONLINE->value => self::make('Online', self::LIVE, 'signal'),
            SourceStatusEnum::OFFLINE->value => self::make('Offline', self::IDLE, 'signal-zero'),
            SourceStatusEnum::ERROR->value => self::make('Error', self::DANGER, 'triangle-alert'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function server(ServerStatusEnum|string|null $status): array
    {
        $value = $status instanceof ServerStatusEnum ? $status->value : $status;

        return match ($value) {
            ServerStatusEnum::ACTIVE->value => self::make('Active', self::OK, 'circle-check'),
            ServerStatusEnum::PROVISIONING->value => self::make('Provisioning', self::WARN, 'loader'),
            ServerStatusEnum::DEPROVISIONING->value => self::make('Deprovisioning', self::DANGER, 'loader'),
            ServerStatusEnum::DELETED->value => self::make('Deleted', self::IDLE, 'circle-x'),
            ServerStatusEnum::ERROR->value => self::make('Error', self::DANGER, 'triangle-alert'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function serverType(ServerTypeEnum|string|null $type): array
    {
        $value = $type instanceof ServerTypeEnum ? $type->value : $type;

        return match ($value) {
            ServerTypeEnum::ORIGIN->value => self::make('Origin', self::WARN, 'radio-tower'),
            ServerTypeEnum::EDGE->value => self::make('Edge', self::OK, 'server'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function health(?string $health): array
    {
        return match ($health) {
            'healthy' => self::make('Healthy', self::OK, 'heart-pulse'),
            'unhealthy' => self::make('Unhealthy', self::DANGER, 'heart-crack'),
            default => self::make('Unknown', self::IDLE, 'circle-help'),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function stream(StreamStatusEnum|string|null $status): array
    {
        $value = $status instanceof StreamStatusEnum ? $status->value : $status;

        return match ($value) {
            StreamStatusEnum::ONLINE->value => self::make('Online', self::LIVE, 'signal'),
            StreamStatusEnum::STARTING_SOON->value => self::make('Starting soon', self::WARN, 'clock'),
            StreamStatusEnum::PROVISIONING->value => self::make('Provisioning', self::WARN, 'loader'),
            StreamStatusEnum::TECHNICAL_ISSUE->value => self::make('Technical issue', self::DANGER, 'triangle-alert'),
            StreamStatusEnum::OFFLINE->value => self::make('Offline', self::IDLE, 'signal-zero'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * Two-state badge, e.g. Auto/Manual or Restricted/Public.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function toggle(
        bool $state,
        string $trueLabel,
        string $falseLabel,
        string $trueTone = self::OK,
        string $falseTone = self::IDLE,
        ?string $trueIcon = null,
        ?string $falseIcon = null,
    ): array {
        return $state
            ? self::make($trueLabel, $trueTone, $trueIcon)
            : self::make($falseLabel, $falseTone, $falseIcon);
    }
}
