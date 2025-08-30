<?php

namespace App\Filament\Resources;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Resources\ServerResource\RelationManagers\ClientsRelationManager;
use App\Filament\Resources\ServerResource\RelationManagers\UserRelationManager;
use App\Models\Server;
use Faker\Provider\Text;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Actions\DeleteAction;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
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
                    ->disabled(fn ($operation): bool => $operation === 'edit')
                    ->default('manual')
                    ->helperText('Use "manual" for locally managed servers')
                    ->required(),

                TextInput::make('hostname')
                    ->disabled(fn ($operation): bool => $operation === 'edit')
                    ->required()
                    ->helperText('For local Docker: use container name (e.g., "ef-streaming-stream-1")'),
                    
                TextInput::make('ip')
                    ->disabled(fn ($operation): bool => $operation === 'edit')
                    ->required()
                    ->helperText('For local Docker: use container IP or "localhost"'),
                    
                TextInput::make('port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(8080)
                    ->required()
                    ->helperText('Server port (80 and 443 will be omitted from URLs)'),

                TextInput::make('shared_secret')
                    ->disabled(fn ($operation): bool => $operation === 'edit')
                    ->default(fn () => \Illuminate\Support\Str::random(40))
                    ->required()
                    ->helperText('Secret key for inter-server authentication'),

                Select::make('type')->options([
                    'origin' => 'Origin',
                    'edge' => 'Edge',
                ])->disabled(fn ($operation): bool => $operation === 'edit')
                ->default('edge')
                ->required(),

                TextInput::make('max_clients')
                    ->minValue(0)
                    ->numeric()
                    ->hidden(fn(?Server $record): bool => $record?->type !== ServerTypeEnum::EDGE)
                    ->maxValue('99999')
                    ->default(100)
                    ->required(),

                Select::make('status')->options([
                    'provisioning' => 'Provisioning',
                    'active' => 'Active',
                    ServerStatusEnum::DEPROVISIONING->value => "Deprovisioning",
                    'deleted' => 'Deleted',
                ])
                ->default('active')
                ->required(),

                Checkbox::make('immutable')
                    ->hidden(fn(?Server $record): bool => $record?->type !== ServerTypeEnum::EDGE)
                    ->reactive()
                    ->default(true)
                    ->helperText('Set this if you want to use this server as a stream server. This will prevent the server from being deleted by autoscaling measures.'),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn(?Server $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn(?Server $record): string => $record?->updated_at?->diffForHumans() ?? '-'),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hetzner_id')
                    ->label('Server ID')
                    ->searchable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        'origin' => 'warning',
                        'edge' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('hostname')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('ip')
                    ->copyable(),
                    
                TextColumn::make('port')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        'active' => 'success',
                        'provisioning' => 'warning',
                        'deprovisioning' => 'danger',
                        'deleted' => 'gray',
                        default => 'secondary',
                    }),
                
                TextColumn::make('max_clients')
                    ->label('Max Clients')
                    ->sortable()
                    ->visible(fn (): bool => true),
            ])->actions([
                EditAction::make(),
                Action::make('Deprovision')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (?Server $record): bool => $record && $record->hetzner_id !== 'manual')
                    ->action(fn(Server $record) => $record->deprovision()),
                Action::make('Delete')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (?Server $record): bool => $record && $record->hetzner_id === 'manual')
                    ->modalHeading('Delete Manual Server')
                    ->modalDescription('Are you sure you want to delete this manually managed server?')
                    ->action(fn(Server $record) => $record->delete()),
            ])->poll();
    }

    public static function getRelations(): array
    {
        return [
            ClientsRelationManager::class
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
