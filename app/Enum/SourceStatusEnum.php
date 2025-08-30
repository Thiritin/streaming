<?php

namespace App\Enum;

enum SourceStatusEnum: string
{
    case ONLINE = 'online';
    case OFFLINE = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Online',
            self::OFFLINE => 'Offline',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ONLINE => 'success',
            self::OFFLINE => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ONLINE => 'heroicon-o-signal',
            self::OFFLINE => 'heroicon-o-signal-slash',
        };
    }
}
