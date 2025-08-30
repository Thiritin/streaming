<?php

namespace App\Services;

use App\Enum\AutoscalerAction;
use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class AutoscalerService
{
    public static function enableAutoscaler(): void
    {
        Cache::set('autoscaler.enabled', true);
    }

    public static function disableAutoscaler(): void
    {
        Cache::set('autoscaler.enabled', false);
    }

    public static function isAutoscalerEnabled(): bool
    {
        return Cache::get('autoscaler.enabled', false);
    }

    public static function availableClientSlots()
    {
        return Server::whereIn('status',
            [ServerStatusEnum::PROVISIONING->value, ServerStatusEnum::ACTIVE->value])
            ->where('type', ServerTypeEnum::EDGE->value)
            ->where('hetzner_id', '!=', 'manual')  // Exclude manual servers from autoscaling
            ->sum('max_clients');
    }

    public static function determineAction(): AutoscalerAction
    {
        // Total active users in stream
        $serverUserCount = StreamInfoService::getUserCount();

        // Capacity of servers that are in provisioning and active max_clients
        $serverCapacity = self::availableClientSlots();


        // Is capacity over 80%
        if ($serverUserCount > ($serverCapacity * 0.8)) {
            return AutoscalerAction::SCALE_UP;
        }
        // Is under capacity 20%
        if ($serverUserCount < ($serverCapacity * 0.2)) {
            return AutoscalerAction::SCALE_DOWN;
        }

        return AutoscalerAction::NONE;

    }
}
