<?php

namespace App\Filament\Resources;

use App\Enum\SourceStatusEnum;
use App\Filament\Resources\SourceResource\Pages;
use App\Filament\Resources\SourceResource\RelationManagers;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationGroup = 'Streaming';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $state, Forms\Set $set) {
                                $slug = Str::slug($state);
                                $set('slug', $slug);
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Stream Configuration')
                    ->description('OBS Studio Configuration')
                    ->schema([
                        Forms\Components\Placeholder::make('obs_server_url')
                            ->label('OBS Server URL')
                            ->content(function (?Source $record) {
                                if (! $record) {
                                    return 'Will be generated on save';
                                }

                                return new \Illuminate\Support\HtmlString(
                                    '<code class="text-sm font-mono select-all cursor-pointer" 
                                          onclick="navigator.clipboard.writeText(this.textContent); this.classList.add(\'opacity-50\'); setTimeout(() => this.classList.remove(\'opacity-50\'), 200);">'
                                    .htmlspecialchars($record->getRtmpServerUrl()).
                                    '</code>'
                                );
                            })
                            ->helperText('Click to copy → OBS Settings → Stream → Server'),
                        Forms\Components\Placeholder::make('obs_stream_key_display')
                            ->label('OBS Stream Key')
                            ->content(function (?Source $record) {
                                if (! $record || ! $record->stream_key) {
                                    return 'Will be generated on save';
                                }

                                return new \Illuminate\Support\HtmlString(
                                    '<code class="text-sm font-mono select-all cursor-pointer" 
                                          onclick="navigator.clipboard.writeText(this.textContent); this.classList.add(\'opacity-50\'); setTimeout(() => this.classList.remove(\'opacity-50\'), 200);">'
                                    .htmlspecialchars($record->getObsStreamKey()).
                                    '</code>'
                                );
                            })
                            ->helperText('Click to copy → OBS Settings → Stream → Stream Key'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                BadgeColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(fn ($record) => $record->status->value)
                    ->colors([
                        'success' => SourceStatusEnum::ONLINE->value,
                        'gray' => SourceStatusEnum::OFFLINE->value,
                    ])
                    ->icons([
                        'heroicon-o-signal' => SourceStatusEnum::ONLINE->value,
                        'heroicon-o-signal-slash' => SourceStatusEnum::OFFLINE->value,
                    ]),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('slug')
                    ->label('Stream Name')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('shows_count')
                    ->label('Total Shows')
                    ->counts('shows')
                    ->sortable(),
                TextColumn::make('live_shows_count')
                    ->label('Live Now')
                    ->getStateUsing(fn ($record) => $record->liveShows()->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SourceStatusEnum::ONLINE->value => 'Online',
                        SourceStatusEnum::OFFLINE->value => 'Offline',
                    ])
                    ->placeholder('All statuses'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function ($record) {
                        if ($record->liveShows()->exists()) {
                            Notification::make()
                                ->title('Cannot delete source')
                                ->body('This source has active live shows.')
                                ->danger()
                                ->send();

                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if ($record->liveShows()->exists()) {
                                    Notification::make()
                                        ->title('Cannot delete sources')
                                        ->body('One or more sources have active live shows.')
                                        ->danger()
                                        ->send();

                                    return false;
                                }
                            }
                        }),
                ]),
            ])
            ->poll('10s');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ShowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSources::route('/'),
            'create' => Pages\CreateSource::route('/create'),
            'edit' => Pages\EditSource::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $onlineCount = static::getModel()::where('status', SourceStatusEnum::ONLINE)->count();
        return $onlineCount > 0 ? (string) $onlineCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}