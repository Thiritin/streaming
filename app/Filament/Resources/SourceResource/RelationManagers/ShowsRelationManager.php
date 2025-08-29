<?php

namespace App\Filament\Resources\SourceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ShowsRelationManager extends RelationManager
{
    protected static string $relationship = 'shows';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'live',
                        'warning' => 'scheduled',
                        'gray' => 'ended',
                        'danger' => 'cancelled',
                    ]),
                Tables\Columns\TextColumn::make('scheduled_start')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('viewer_count')
                    ->label('Viewers')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_featured')
                    ->label('Featured'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}