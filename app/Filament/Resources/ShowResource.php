<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShowResource\Pages;
use App\Filament\Resources\ShowResource\RelationManagers;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ShowResource extends Resource
{
    protected static ?string $model = Show::class;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $navigationGroup = 'Streaming';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Show Information')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $state, Forms\Set $set, ?Show $record) {
                                if (! $record) {
                                    $set('slug', Str::slug($state.'-'.now()->format('Y-m-d')));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('source_id')
                            ->label('Source')
                            ->required()
                            ->options(Source::ordered()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->helperText('Select the stream source for this show'),
                        Select::make('server_id')
                            ->label('Streaming Server')
                            ->options(Server::where('status', 'available')->pluck('hostname', 'id'))
                            ->searchable()
                            ->preload()
                            ->helperText('Optional: Assign a specific server'),
                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Schedule')
                    ->schema([
                        DateTimePicker::make('scheduled_start')
                            ->label('Scheduled Start')
                            ->required()
                            ->seconds(false)
                            ->timezone('Europe/Berlin'),
                        DateTimePicker::make('scheduled_end')
                            ->label('Scheduled End')
                            ->required()
                            ->seconds(false)
                            ->timezone('Europe/Berlin')
                            ->after('scheduled_start'),
                        DateTimePicker::make('actual_start')
                            ->label('Actual Start')
                            ->seconds(false)
                            ->timezone('Europe/Berlin')
                            ->disabled()
                            ->dehydrated(),
                        DateTimePicker::make('actual_end')
                            ->label('Actual End')
                            ->seconds(false)
                            ->timezone('Europe/Berlin')
                            ->disabled()
                            ->dehydrated(),
                    ])
                    ->columns(2),

                Section::make('Status & Settings')
                    ->schema([
                        Select::make('status')
                            ->options([
                                'scheduled' => 'Scheduled',
                                'live' => 'Live',
                                'ended' => 'Ended',
                                'cancelled' => 'Cancelled',
                            ])
                            ->required()
                            ->disabled(fn (?Show $record) => $record && $record->status === 'live')
                            ->helperText('Use the Go Live/End Stream buttons to manage live status'),
                        Toggle::make('auto_mode')
                            ->label('Auto Mode')
                            ->helperText('When enabled, show will automatically start/end based on source status and scheduled times')
                            ->hint('Show starts when source goes online after scheduled start, ends when source goes offline after scheduled end'),
                        FileUpload::make('thumbnail_path')
                            ->label('Thumbnail')
                            ->image()
                            ->disk('s3')
                            ->directory('shows/thumbnails')
                            ->maxSize(5120) // 5MB max
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->imagePreviewHeight('250')
                            ->visibility('private')
                            ->preserveFilenames()
                            ->loadStateFromRelationshipsUsing(static function (FileUpload $component, ?Show $record): void {
                                if ($record && $record->thumbnail_path) {
                                    // Set the stored path value so Filament knows where the file is
                                    $component->state($record->thumbnail_path);
                                }
                            })
                            ->columnSpanFull(),
                        TagsInput::make('tags')
                            ->separator(',')
                            ->suggestions([
                                'Main Stage',
                                'Panel',
                                'Workshop',
                                'Performance',
                                'Interview',
                                'Opening Ceremony',
                                'Closing Ceremony',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Statistics')
                    ->schema([
                        Placeholder::make('viewer_count')
                            ->label('Current Viewers')
                            ->content(fn (?Show $record) => $record ? $record->viewer_count : 0),
                        Placeholder::make('peak_viewer_count')
                            ->label('Peak Viewers')
                            ->content(fn (?Show $record) => $record ? $record->peak_viewer_count : 0),
                        Placeholder::make('duration')
                            ->label('Duration')
                            ->content(fn (?Show $record) => $record ? $record->formatted_duration : '—'),
                    ])
                    ->columns(3)
                    ->visible(fn (?Show $record) => $record !== null),

                Section::make('Additional Configuration')
                    ->schema([
                        KeyValue::make('metadata')
                            ->label('Metadata')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->addButtonLabel('Add Metadata'),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_url')  // Use the accessor that returns signed URL
                    ->label('Thumbnail')
                    ->square()
                    ->size(40),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('source.name')
                    ->label('Source')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'live',
                        'warning' => 'scheduled',
                        'gray' => 'ended',
                        'danger' => 'cancelled',
                    ])
                    ->icons([
                        'heroicon-o-signal' => 'live',
                        'heroicon-o-clock' => 'scheduled',
                        'heroicon-o-check-circle' => 'ended',
                        'heroicon-o-x-circle' => 'cancelled',
                    ]),
                TextColumn::make('scheduled_start')
                    ->label('Scheduled')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
                TextColumn::make('actual_start')
                    ->label('Went Live')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->placeholder('Not started')
                    ->toggleable(),
                TextColumn::make('viewer_count')
                    ->label('Viewers')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('peak_viewer_count')
                    ->label('Peak')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                BadgeColumn::make('auto_mode')
                    ->label('Auto')
                    ->getStateUsing(fn ($record) => $record->auto_mode ? 'Auto' : 'Manual')
                    ->colors([
                        'success' => fn ($state) => $state === 'Auto',
                        'gray' => fn ($state) => $state === 'Manual',
                    ])
                    ->icons([
                        'heroicon-o-cog' => fn ($state) => $state === 'Auto',
                        'heroicon-o-hand-raised' => fn ($state) => $state === 'Manual',
                    ]),
                TextColumn::make('tags')
                    ->badge()
                    ->separator(',')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('scheduled_start', 'asc')
            ->filters([
                Tables\Filters\Filter::make('hide_ended')
                    ->query(fn (Builder $query): Builder => $query->where('status', '!=', 'ended'))
                    ->label('Hide Ended Shows')
                    ->default(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'scheduled' => 'Scheduled',
                        'live' => 'Live',
                        'ended' => 'Ended',
                        'cancelled' => 'Cancelled',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('source')
                    ->relationship('source', 'name'),
                Tables\Filters\Filter::make('today')
                    ->query(fn (Builder $query): Builder => $query->today())
                    ->label('Today\'s Shows'),
                Tables\Filters\Filter::make('upcoming')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->label('Upcoming Shows'),
            ])
            ->actions([
                Action::make('go_live')
                    ->label('Go Live')
                    ->icon('heroicon-o-signal')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Start Live Stream')
                    ->modalDescription('Are you sure you want to start this show? This will mark it as live and notify viewers.')
                    ->modalSubmitActionLabel('Go Live')
                    ->visible(fn (Show $record) => $record->status === 'scheduled')
                    ->action(function (Show $record) {
                        $record->goLive();
                        Notification::make()
                            ->title('Show is now live!')
                            ->body("'{$record->title}' is now streaming.")
                            ->success()
                            ->send();
                    }),
                Action::make('end_stream')
                    ->label('End Stream')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('End Live Stream')
                    ->modalDescription('Are you sure you want to end this show? This will stop the stream and disconnect all viewers.')
                    ->modalSubmitActionLabel('End Stream')
                    ->visible(fn (Show $record) => $record->status === 'live')
                    ->action(function (Show $record) {
                        $record->endLivestream();
                        Notification::make()
                            ->title('Stream ended')
                            ->body("'{$record->title}' has ended.")
                            ->success()
                            ->send();
                    }),
                Action::make('view_stats')
                    ->label('View Statistics')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->url(fn (Show $record) => static::getUrl('statistics', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Show $record) {
                        if ($record->status === 'live') {
                            Notification::make()
                                ->title('Cannot delete live show')
                                ->body('Please end the stream before deleting.')
                                ->danger()
                                ->send();

                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('cancel_shows')
                        ->label('Cancel Shows')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                if ($record->status === 'scheduled') {
                                    $record->cancel();
                                }
                            }
                            Notification::make()
                                ->title('Shows cancelled')
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if ($record->status === 'live') {
                                    Notification::make()
                                        ->title('Cannot delete shows')
                                        ->body('One or more shows are currently live.')
                                        ->danger()
                                        ->send();

                                    return false;
                                }
                            }
                        }),
                ]),
            ])
            ->headerActions([
                Action::make('live_dashboard')
                    ->label('Live Dashboard')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->url(route('filament.admin.pages.stream'))
                    ->openUrlInNewTab(),
            ])
            ->poll('5s');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ViewersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShows::route('/'),
            'create' => Pages\CreateShow::route('/create'),
            'edit' => Pages\EditShow::route('/{record}/edit'),
            'statistics' => Pages\ViewShowStatistics::route('/{record}/statistics'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $liveCount = static::getModel()::live()->count();
        $upcomingCount = static::getModel()::upcoming()->count();

        if ($liveCount > 0) {
            return $liveCount.' live';
        }

        if ($upcomingCount > 0) {
            return $upcomingCount.' upcoming';
        }

        return null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $liveCount = static::getModel()::live()->count();

        if ($liveCount > 0) {
            return 'success';
        }

        return 'warning';
    }
}
