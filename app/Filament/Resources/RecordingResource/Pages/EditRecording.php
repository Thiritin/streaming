<?php

namespace App\Filament\Resources\RecordingResource\Pages;

use App\Filament\Resources\RecordingResource;
use App\Jobs\ProcessRecordingJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRecording extends EditRecord
{
    protected static string $resource = RecordingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('regenerate_thumbnail')
                ->label('Regenerate Thumbnail')
                ->icon('heroicon-o-photo')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerate Thumbnail')
                ->modalDescription('This will capture a new thumbnail from the video. The old thumbnail will be replaced.')
                ->modalSubmitActionLabel('Regenerate')
                ->action(function () {
                    $record = $this->record;
                    
                    // Clear the current thumbnail path to force regeneration
                    $record->thumbnail_path = null;
                    $record->thumbnail_capture_error = null;
                    $record->save();
                    
                    // Dispatch the job to process the recording
                    ProcessRecordingJob::dispatch($record);
                    
                    Notification::make()
                        ->title('Thumbnail regeneration started')
                        ->body('The thumbnail is being regenerated in the background. The page will refresh automatically.')
                        ->success()
                        ->send();
                    
                    // Redirect to refresh the page after a delay
                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                })
                ->visible(fn () => $this->record->m3u8_url !== null),
            Actions\DeleteAction::make(),
        ];
    }
}