<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\MessagesRelationManager;
use App\Models\User;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables\Columns\TextColumn;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $recordTitleAttribute = 'name';

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

                Checkbox::make('is_provisioning'),

                TextInput::make('reg_id')
                    ->disabled()
                    ->integer(),

                DatePicker::make('timeout_expires_at')
                    ->label('Timeout Expires Date'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn(?User $record): string => $record?->updated_at?->diffForHumans() ?? '-'),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn(?User $record): string => $record?->created_at?->diffForHumans() ?? '-'),
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

                TextColumn::make('timeout_expires_at')
                    ->label('Timeout Expires Date')
                    ->date(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
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
