<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateServer extends CreateRecord
{
    protected static string $resource = ServerResource::class;
    
    protected function afterCreate(): void
    {
        // If this is a Hetzner-managed server (no hetzner_id means we want to provision it)
        if (empty($this->record->hetzner_id) && $this->record->hostname === 'auto-provision') {
            // Dispatch the Hetzner provisioning job
            CreateVirtualMachineJob::dispatch($this->record);
            
            Notification::make()
                ->title('Server Provisioning Started')
                ->body('The server is being provisioned on Hetzner Cloud. This may take a few minutes.')
                ->success()
                ->send();
        }
    }
}
