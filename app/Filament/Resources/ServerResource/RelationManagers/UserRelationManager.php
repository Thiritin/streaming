<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use App\Models\User;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserRelationManager extends RelationManager
{
    protected static string $relationship = 'user';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('sub')
                    ->required(),

                TextInput::make('name')
                    ->required(),

                Placeholder::make('created_at')
                    ->label('Created Date')
                    ->content(fn (?User $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label('Last Modified Date')
                    ->content(fn (?User $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sub'),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
            ]);
    }
}
