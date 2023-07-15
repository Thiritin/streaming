<?php

namespace App\Filament\Widgets;

use App\Models\ServerUser;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class ServerUserActive extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';

    protected int | string | array $columnSpan = 1;

    protected function getCards(): array
    {
        return [
            Card::make('Active clients', ServerUser::whereNull('stop')->whereNotNull('start')->count()),
        ];
    }
}