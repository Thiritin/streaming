<?php

namespace App\Filament\Resources\ShowResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ViewersRelationManager extends RelationManager
{
    protected static string $relationship = 'viewerSessions';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('user.name')
                    ->label('Name')
                    ->disabled(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('user.name')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('joined_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('left_at')
                    ->label('Left')
                    ->dateTime()
                    ->placeholder('Still watching')
                    ->sortable(),
                Tables\Columns\TextColumn::make('watch_duration')
                    ->label('Duration')
                    ->getStateUsing(function ($record) {
                        $duration = $record->watch_duration;
                        if (! $duration) {
                            return '—';
                        }

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
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('is_active')
                    ->label('Status')
                    ->getStateUsing(fn ($record) => $record->is_active ? 'Active' : 'Inactive')
                    ->colors([
                        'success' => fn ($state) => $state === 'Active',
                        'gray' => fn ($state) => $state === 'Inactive',
                    ]),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->query(fn (Builder $query): Builder => $query->active())
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
