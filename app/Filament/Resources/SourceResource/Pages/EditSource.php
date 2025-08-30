<?php

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\SourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('regenerate_key')
                ->label('Regenerate Stream Key')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerate Stream Key?')
                ->modalDescription('This will invalidate the current stream key. Any active streams will be disconnected.')
                ->action(function () {
                    $newKey = Str::random(32);
                    $this->record->stream_key = $newKey;
                    $this->record->save();
                    
                    // Refresh the form to show the new key
                    $this->fillForm();
                    
                    Notification::make()
                        ->title('Stream key regenerated')
                        ->body('The new stream key has been saved and is now active.')
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
