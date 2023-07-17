<?php

namespace App\Filament\Resources;

use App\Enum\ServerStatusEnum;
use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Resources\ServerResource\RelationManagers\UserRelationManager;
use App\Models\Server;
use Faker\Provider\Text;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables\Columns\TextColumn;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static ?string $slug = 'servers';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('hetzner_id')
                    ->disabled()
                    ->required(),

                TextInput::make('hostname')
                    ->disabled()
                    ->required(),

                Select::make('type')->options([
                    'origin' => 'Origin',
                    'edge' => 'Edge',
                ])->disabled(),

                TextInput::make('max_clients')
                    ->minValue(0)
                    ->numeric()
                    ->maxValue('99999')
                    ->required(),

                Select::make('status')->options([
                    'provisioning' => 'Provisioning',
                    'active' => 'Active',
                    ServerStatusEnum::DEPROVISIONING->value => "Deprovisioning",
                    'deleted' => 'Deleted',
                ]),

                Checkbox::make('immutable')->helperText('Set this if you want to use this server as a stream server. This will prevent the server from being deleted by autoscaling measures.'),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn(?Server $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn(?Server $record): string => $record?->updated_at?->diffForHumans() ?? '-'),

                Placeholder::make('stream_url')->disabled()->content(fn(?Server $record): string => "rtmp://" . $record->hostname .":1935/live?streamkey=".config('app.stream_key') ?? '-'),
                Placeholder::make('streamkey')->disabled()->content(fn(?Server $record): string => "livestream" ?? '-'),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hetzner_id'),

                TextColumn::make('type'),

                TextColumn::make('hostname'),

                TextColumn::make('ip'),

                TextColumn::make('status'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            UserRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServers::route('/'),
            'create' => Pages\CreateServer::route('/create'),
            'edit' => Pages\EditServer::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }
}
