<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Jobs\Server\CreateServerJob;
use App\Services\AutoscalerService;
use Filament\Pages\Actions\Action;
use Filament\Pages\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServers extends ListRecords
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Manual Server')
                ->icon('heroicon-o-plus'),
            Action::make('Enable Autoscaler')->action(fn () => AutoscalerService::enableAutoscaler())->hidden(AutoscalerService::isAutoscalerEnabled())->color('success'),
            Action::make('Disable Autoscaler')->action(fn () => AutoscalerService::disableAutoscaler())->hidden(! AutoscalerService::isAutoscalerEnabled())->color('danger'),
            Action::make('Provision Cloud Server')
                ->action(fn () => CreateServerJob::dispatch())
                ->icon('heroicon-o-cloud')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Provision New Cloud Server')
                ->modalDescription('This will provision a new server on Hetzner Cloud. Are you sure?'),
        ];
    }
}
