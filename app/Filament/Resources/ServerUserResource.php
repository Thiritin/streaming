<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerUserResource\Pages;
use App\Models\ServerUser;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServerUserResource extends Resource
{
    protected static ?string $model = ServerUser::class;

    protected static ?string $slug = 'server-users';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('server_id')->relationship('server', 'hostname'),

                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),

                // client is dropdown of either web or vlc
                Select::make('client')->options([
                    'web' => 'Web',
                    'vlc' => 'VLC',
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('server.hostname')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),


                // Badge of either web or vlc with matching colors
                BadgeColumn::make('client')
                    ->colors([
                        'primary' => 'web',
                        'secondary' => 'vlc',
                    ]),

                TextColumn::make('client_id'),

                TextColumn::make('start')
                    ->dateTime(),

                TextColumn::make('stop')
                    ->dateTime(),
            ])
            ->filters([
                // Create filter only show connected user where stop is null and start is not null
                Filter::make('connected')->query(fn($query) => $query->whereNull('stop')->whereNotNull('start'))->default(true),
                // Filter by Server
                SelectFilter::make('server')->options(fn() => \App\Models\Server::pluck('hostname', 'id'))->attribute('server.hostname'),
            ])->bulkActions([
                BulkAction::make('disconnect')
                    ->action(fn($records) => $records->each->disconnect())
                    ->requiresConfirmation()
                    ->color('danger')
                    ->icon('heroicon-o-trash'),
            ])
            ->poll();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServerUsers::route('/'),
            'create' => Pages\CreateServerUser::route('/create'),
            'edit' => Pages\EditServerUser::route('/{record}/edit'),
        ];
    }

    protected static function getGlobalSearchEloquentQuery(): Builder
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
