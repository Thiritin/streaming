<?php

namespace App\Filament\Resources\EmoteResource\Pages;

use App\Filament\Resources\EmoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmote extends EditRecord
{
    protected static string $resource = EmoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // If approving for the first time
        if (($data['is_approved'] ?? false) && ! $this->record->is_approved) {
            $data['approved_by_user_id'] = auth()->id();
            $data['approved_at'] = now();
        }

        return $data;
    }
}
