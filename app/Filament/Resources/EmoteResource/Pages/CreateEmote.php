<?php

namespace App\Filament\Resources\EmoteResource\Pages;

use App\Filament\Resources\EmoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmote extends CreateRecord
{
    protected static string $resource = EmoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by_user_id'] = auth()->id();

        if ($data['is_approved'] ?? false) {
            $data['approved_by_user_id'] = auth()->id();
            $data['approved_at'] = now();
        }

        return $data;
    }
}
