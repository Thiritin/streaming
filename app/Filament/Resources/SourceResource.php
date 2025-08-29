<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SourceResource\Pages;
use App\Filament\Resources\SourceResource\RelationManagers;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';
    
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
                            ->afterStateUpdated(fn (string $state, Forms\Set $set) => 
                                $set('slug', Str::slug($state))
                            ),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('location')
                            ->label('Physical Location')
                            ->placeholder('e.g., Hall 3, Outside, Main Stage')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Section::make('Stream Configuration')
                    ->schema([
                        TextInput::make('stream_key')
                            ->label('Stream Key')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->default(fn () => Str::random(32))
                            ->copyable()
                            ->revealable()
                            ->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('This key is used for RTMP push and is stored encrypted'),
                        TextInput::make('rtmp_url')
                            ->label('RTMP URL')
                            ->url()
                            ->placeholder('rtmp://localhost:1935/live/')
                            ->helperText('Leave empty to auto-generate'),
                        TextInput::make('flv_url')
                            ->label('FLV URL')
                            ->url()
                            ->placeholder('http://localhost:8080/live/')
                            ->helperText('Leave empty to auto-generate'),
                        TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->helperText('Higher priority sources appear first'),
                    ])
                    ->columns(2),
                
                Section::make('Settings')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive sources cannot receive streams'),
                        Toggle::make('is_primary')
                            ->label('Primary Source')
                            ->helperText('Mark as the main/permanent stream source'),
                        KeyValue::make('metadata')
                            ->label('Additional Configuration')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->addButtonLabel('Add Configuration')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('location')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                BadgeColumn::make('is_primary')
                    ->label('Type')
                    ->getStateUsing(fn ($record) => $record->is_primary ? 'Primary' : 'Secondary')
                    ->colors([
                        'success' => fn ($state) => $state === 'Primary',
                        'gray' => fn ($state) => $state === 'Secondary',
                    ]),
                TextColumn::make('shows_count')
                    ->label('Total Shows')
                    ->counts('shows')
                    ->sortable(),
                TextColumn::make('live_shows_count')
                    ->label('Live Now')
                    ->getStateUsing(fn ($record) => $record->liveShows()->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('stream_key')
                    ->label('Stream Key')
                    ->copyable()
                    ->toggleable()
                    ->limit(20)
                    ->tooltip(fn ($record) => $record->stream_key),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->onColor('success')
                    ->offColor('danger')
                    ->afterStateUpdated(function ($record, $state) {
                        Notification::make()
                            ->title($state ? 'Source activated' : 'Source deactivated')
                            ->success()
                            ->send();
                    }),
                TextColumn::make('priority')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->boolean()
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->placeholder('All sources'),
                Tables\Filters\TernaryFilter::make('is_primary')
                    ->label('Source Type')
                    ->boolean()
                    ->trueLabel('Primary only')
                    ->falseLabel('Secondary only')
                    ->placeholder('All types'),
            ])
            ->actions([
                Tables\Actions\Action::make('copy_urls')
                    ->label('Copy URLs')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('gray')
                    ->modalHeading('Stream URLs')
                    ->modalContent(fn ($record) => view('filament.modals.source-urls', ['source' => $record]))
                    ->modalSubmitAction(false),
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
            ]);
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
        return static::getModel()::active()->count();
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::active()->count() > 0 ? 'success' : 'gray';
    }
}