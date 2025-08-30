<?php

namespace App\Filament\Widgets;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class Capacity extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 1;

    protected function getCards(): array
    {
        $maxClients = Server::where('status', ServerStatusEnum::ACTIVE)->where('type', ServerTypeEnum::EDGE)->sum('max_clients');
        $maxClientsProvisioning = Server::where('status', ServerStatusEnum::PROVISIONING)->where('type', ServerTypeEnum::EDGE)->sum('max_clients');
        $waitingUsers = User::where('is_provisioning', true)->count();

        return [
            Card::make('Max clients', $maxClients),
            Card::make('Booting Capacity', $maxClientsProvisioning),
            Card::make('Waiting Users', $waitingUsers),
        ];
    }
}
