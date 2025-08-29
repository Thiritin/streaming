<?php

namespace App\Filament\Resources\ShowResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ViewersRelationManager extends RelationManager
{
    protected static string $relationship = 'viewers';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pivot.joined_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.left_at')
                    ->label('Left')
                    ->dateTime()
                    ->placeholder('Still watching')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.watch_duration')
                    ->label('Duration')
                    ->getStateUsing(function ($record) {
                        $duration = $record->pivot->watch_duration;
                        if (!$duration) return '—';
                        
                        $hours = floor($duration / 3600);
                        $minutes = floor(($duration % 3600) / 60);
                        $seconds = $duration % 60;
                        
                        if ($hours > 0) {
                            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
                        } elseif ($minutes > 0) {
                            return sprintf('%dm %ds', $minutes, $seconds);
                        } else {
                            return sprintf('%ds', $seconds);
                        }
                    }),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->query(fn (Builder $query): Builder => $query->whereNull('show_user.left_at'))
                    ->label('Currently Watching'),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }
}