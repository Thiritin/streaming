<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $slug = 'clients';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('client')->options([
                    'web' => 'Web',
                    'vlc' => 'VLC',
                ])->disabled(),

                TextInput::make('client_id')->disabled(),

                DateTimePicker::make('start'),

                DateTimePicker::make('stop'),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn (?Client $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn (?Client $record): string => $record?->updated_at?->diffForHumans() ?? '-'),

                Select::make('server_id')
                    ->required()
                    ->searchable()
                    ->relationship('server', 'hostname'),

                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client'),

                TextColumn::make('client_id'),

                TextColumn::make('start')
                    ->date(),

                TextColumn::make('stop')
                    ->date(),

                TextColumn::make('server_id'),

                TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
            ])->filters([
                Filter::make('connected')->query(fn ($query) => $query->connected())->default(true),
                // Filter by Server
                SelectFilter::make('server')->options(fn () => \App\Models\Server::pluck('hostname', 'id'))->attribute('server.hostname'),
            ])->actions([
                \Filament\Tables\Actions\EditAction::make(),
                Action::make('disconnect_single')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->action(fn (Client $record) => $record->disconnect())
                    ->label('Disconnect'),
            ])->bulkActions([
                BulkAction::make('disconnect_bulk')
                    ->action(fn (Collection $records) => $records->each->disconnect())
                    ->requiresConfirmation()
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->label('Disconnect Clients')
                    ->deselectRecordsAfterCompletion(),
                DeleteBulkAction::make(),
            ])->poll(10);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['user']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['user.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [];

        if ($record->user) {
            $details['User'] = $record->user->name;
        }

        return $details;
    }
}
