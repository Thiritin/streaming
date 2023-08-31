<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Jobs\Server\CreateServerJob;
use App\Services\AutoscalerService;
use Filament\Pages\Actions\Action;
use Filament\Pages\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;

class ListServers extends ListRecords
{
    protected static string $resource = ServerResource::class;

    protected function getActions(): array
    {
        return [
            Action::make('Enable Autoscaler')->action(fn() => AutoscalerService::enableAutoscaler())->hidden(AutoscalerService::isAutoscalerEnabled())->color('success'),
            Action::make('Disable Autoscaler')->action(fn() => AutoscalerService::disableAutoscaler())->hidden(!AutoscalerService::isAutoscalerEnabled())->color('danger'),
            Action::make('Provision New Server')->action(fn() => CreateServerJob::dispatch()),
        ];
    }
}
