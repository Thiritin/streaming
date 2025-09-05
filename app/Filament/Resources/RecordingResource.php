<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecordingResource\Pages;
use App\Models\Recording;
use App\Models\Show;
use App\Jobs\ProcessRecordingJob;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RecordingResource extends Resource
{
    protected static ?string $model = Recording::class;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('show_id')
                    ->label('Associated Show')
                    ->options(Show::with('source')->get()->mapWithKeys(function ($show) {
                        return [$show->id => $show->title . ' (' . $show->source->name . ')']; 
                    }))
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->reactive()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        if ($state) {
                            $show = Show::find($state);
                            if ($show) {
                                $set('title', $show->title);
                                $set('description', $show->description);
                                if ($show->actual_start) {
                                    $set('date', $show->actual_start);
                                }
                                if ($show->actual_start && $show->actual_end) {
                                    $duration = $show->actual_start->diffInSeconds($show->actual_end);
                                    $set('duration', $duration);
                                }
                            }
                        }
                    })
                    ->helperText('Select a show to auto-populate fields'),
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->reactive()
                    ->afterStateUpdated(function ($state, Forms\Set $set, ?Recording $record) {
                        if (!$record && filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(Recording::class, 'slug', ignoreRecord: true)
                    ->helperText('URL-friendly version of the title'),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('date')
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('duration')
                    ->numeric()
                    ->suffix('seconds')
                    ->helperText('Duration in seconds (will be auto-filled via ffmpeg if left empty)')
                    ->nullable(),
                Forms\Components\TextInput::make('m3u8_url')
                    ->label('M3U8 URL')
                    ->required()
                    ->url()
                    ->columnSpanFull()
                    ->helperText('URL to the HLS playlist file'),
                Forms\Components\FileUpload::make('thumbnail_path')
                    ->label('Thumbnail')
                    ->image()
                    ->imageResizeMode('cover')
                    ->imageResizeTargetWidth(1280)
                    ->imageResizeTargetHeight(720)
                    ->disk('s3')
                    ->directory('recordings/thumbnails')
                    ->visibility('private')
                    ->columnSpanFull()
                    ->helperText('Upload a thumbnail or leave empty to auto-generate from first frame')
                    ->loadStateFromRelationshipsUsing(static function (Forms\Components\FileUpload $component, ?Recording $record): void {
                        if ($record && $record->thumbnail_path) {
                            $component->state($record->thumbnail_path);
                        }
                    }),
                Forms\Components\Toggle::make('is_published')
                    ->label('Published')
                    ->default(true)
                    ->helperText('Only published recordings will be visible to users'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Thumbnail')
                    ->size(80)
                    ->height(45),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('show.title')
                    ->label('Show')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('date')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration')
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
                            return '-';
                        }
                        $hours = floor($state / 3600);
                        $minutes = floor(($state % 3600) / 60);
                        $seconds = $state % 60;
                        if ($hours > 0) {
                            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
                        }

                        return sprintf('%d:%02d', $minutes, $seconds);
                    })
                    ->label('Duration'),
                Tables\Columns\TextColumn::make('views')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean()
                    ->label('Published'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('Published'),
            ])
            ->actions([
                Tables\Actions\Action::make('regenerate_thumbnail')
                    ->label('Regenerate Thumbnail')
                    ->icon('heroicon-o-photo')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Regenerate Thumbnail')
                    ->modalDescription('This will capture a new thumbnail from the video. The old thumbnail will be replaced.')
                    ->modalSubmitActionLabel('Regenerate')
                    ->action(function (Recording $record) {
                        // Clear the current thumbnail path to force regeneration
                        $record->thumbnail_path = null;
                        $record->thumbnail_capture_error = null;
                        $record->save();
                        
                        // Dispatch the job to process the recording
                        ProcessRecordingJob::dispatch($record);
                        
                        Notification::make()
                            ->title('Thumbnail regeneration started')
                            ->body('The thumbnail is being regenerated in the background. Please refresh the page in a few moments to see the new thumbnail.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Recording $record) => $record->m3u8_url !== null),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('regenerate_thumbnails')
                        ->label('Regenerate Thumbnails')
                        ->icon('heroicon-o-photo')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Regenerate Thumbnails')
                        ->modalDescription('This will capture new thumbnails for all selected recordings. The old thumbnails will be replaced.')
                        ->modalSubmitActionLabel('Regenerate All')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->m3u8_url) {
                                    $record->thumbnail_path = null;
                                    $record->thumbnail_capture_error = null;
                                    $record->save();
                                    ProcessRecordingJob::dispatch($record);
                                    $count++;
                                }
                            }
                            
                            Notification::make()
                                ->title('Thumbnails regeneration started')
                                ->body("Regenerating thumbnails for {$count} recording(s) in the background.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecordings::route('/'),
            'create' => Pages\CreateRecording::route('/create'),
            'edit' => Pages\EditRecording::route('/{record}/edit'),
        ];
    }
}
