<?php

namespace App\Filament\Resources\ServerUserResource\Pages;

use App\Filament\Resources\ServerUserResource;
use Filament\Pages\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\BulkAction;

class ListServerUsers extends ListRecords
{
    protected static string $resource = ServerUserResource::class;

    protected function getActions(): array
    {
        return [
            CreateAction::make()
        ];
    }
}
