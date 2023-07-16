<?php

namespace App\Filament\Resources\ServerUserResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientsRelationManager extends RelationManager
{
    protected static string $relationship = 'clients';

    protected static ?string $recordTitleAttribute = 'client_id';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client_id'),
                TextColumn::make('client_id'),

                BadgeColumn::make('client')
                    ->colors([
                        'primary' => 'web',
                        'secondary' => 'vlc',
                    ]),

                TextColumn::make('client_id'),

                TextColumn::make('start')
                    ->date(),

                TextColumn::make('stop')
                    ->date(),
            ])
            ->filters([
                Filter::make('connected')->query(fn($query) => $query->whereNull('stop')->whereNotNull('start'))->default(true),
            ])
            ->headerActions([
            ])
            ->actions([
            ])
            ->bulkActions([
                BulkAction::make('disconnect')
                    ->action(fn($records) => $records->each->disconnect())
                    ->requiresConfirmation()
                    ->color('danger')
                    ->icon('heroicon-o-trash'),
            ])->poll();
    }
}
