<?php

namespace App\Enum;

enum SourceStatusEnum: string
{
    case ONLINE = 'online';
    case OFFLINE = 'offline';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Online',
            self::OFFLINE => 'Offline',
            self::ERROR => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ONLINE => 'success',
            self::OFFLINE => 'gray',
            self::ERROR => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ONLINE => 'heroicon-o-signal',
            self::OFFLINE => 'heroicon-o-signal-slash',
            self::ERROR => 'heroicon-o-exclamation-triangle',
        };
    }
}
