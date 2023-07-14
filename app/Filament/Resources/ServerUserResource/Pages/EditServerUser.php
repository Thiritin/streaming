<?php

namespace App\Filament\Resources\ServerUserResource\Pages;

use App\Filament\Resources\ServerUserResource;
use Filament\Pages\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServerUser extends EditRecord
{
    protected static string $resource = ServerUserResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
