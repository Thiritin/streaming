<?php

namespace App\Filament\Resources;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\RolesRelationManager;
use App\Models\User;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $recordTitleAttribute = 'name';
    
    protected static ?string $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $navigationGroup = 'User Management';
    
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('sub')
                    ->disabled()
                    ->required(),

                TextInput::make('name')
                    ->disabled()
                    ->required(),

                TextInput::make('reg_id')
                    ->disabled()
                    ->integer(),

                Select::make('server_id')
                    ->relationship('server', 'hostname',
                        fn (Builder $query) => $query
                            ->where('type', ServerTypeEnum::EDGE)
                            ->where('status', ServerStatusEnum::ACTIVE))
                    ->nullable(),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn (?User $record): string => $record?->updated_at?->diffForHumans() ?? '-'),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn (?User $record): string => $record?->created_at?->diffForHumans() ?? '-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sub'),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reg_id'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RolesRelationManager::class,
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }
}
