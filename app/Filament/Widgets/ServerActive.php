<?php

namespace App\Filament\Widgets;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class ServerActive extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';

    protected int | string | array $columnSpan = 1;

    protected function getCards(): array
    {
        $server = Server::groupBy('status')->where('type',ServerTypeEnum::EDGE)->select(['status',\DB::raw('COUNT(servers.id) AS count')])->get();
        $widget = [];
        foreach ($server as $s) {
            $widget[] = Card::make("Edge Server ".$s->status->value, $s->count);
        }
        return $widget;
    }
}
