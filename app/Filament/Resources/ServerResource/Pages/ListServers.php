<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Jobs\Server\CreateServerJob;
use App\Services\AutoscalerService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use App\Enum\ServerTypeEnum;
use App\Models\Server;

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
            Action::make('provisionCloudServer')
                ->label('Provision Cloud Server')
                ->icon('heroicon-o-cloud')
                ->color('primary')
                ->form([
                    Select::make('type')
                        ->label('Server Type')
                        ->options([
                            ServerTypeEnum::ORIGIN->value => 'Origin Server (ccx43 - High Performance)',
                            ServerTypeEnum::EDGE->value => 'Edge Server (cpx21 - Standard)',
                        ])
                        ->default(ServerTypeEnum::EDGE->value)
                        ->required()
                        ->helperText('Origin servers handle stream ingestion and transcoding. Edge servers cache and distribute content.'),
                ])
                ->action(function (array $data): void {
                    // Check if we can create an origin server
                    if ($data['type'] === ServerTypeEnum::ORIGIN->value) {
                        $existingOrigin = Server::where('type', ServerTypeEnum::ORIGIN)
                            ->whereIn('status', ['active', 'provisioning'])
                            ->exists();
                        
                        if ($existingOrigin) {
                            Notification::make()
                                ->title('Cannot Create Origin Server')
                                ->body('An origin server already exists or is being provisioned. Only one origin server is allowed.')
                                ->danger()
                                ->send();
                            return;
                        }
                    }
                    
                    // Create the server directly with the specified type
                    $server = Server::create([
                        'type' => $data['type'],
                        'status' => 'provisioning',
                        'hostname' => 'pending',
                        'port' => 443,
                        'shared_secret' => \Illuminate\Support\Str::random(40),
                        'max_clients' => ($data['type'] === ServerTypeEnum::ORIGIN->value) ? 1000 : 100,
                    ]);
                    
                    // Dispatch provisioning job
                    \App\Jobs\Server\Provision\CreateVirtualMachineJob::dispatch($server);
                    
                    Notification::make()
                        ->title('Server Provisioning Started')
                        ->body("A new {$data['type']} server is being provisioned on Hetzner Cloud.")
                        ->success()
                        ->send();
                })
                ->modalHeading('Provision New Cloud Server')
                ->modalDescription('Select the type of server to provision on Hetzner Cloud.')
                ->modalSubmitActionLabel('Start Provisioning'),
        ];
    }
}
