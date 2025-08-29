<?php

namespace App\Filament\Resources\RoleResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Notifications\Notification;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

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
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.assigned_at')
                    ->label('Assigned')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('pivot.assigned_by')
                    ->label('Assigned By')
                    ->colors([
                        'success' => 'manual',
                        'warning' => 'login',
                        'info' => 'system',
                    ]),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->query(fn (Builder $query): Builder => 
                        $query->where(function ($q) {
                            $q->whereNull('role_user.expires_at')
                              ->orWhere('role_user.expires_at', '>', now());
                        })
                    )
                    ->label('Active Only'),
                Tables\Filters\Filter::make('expired')
                    ->query(fn (Builder $query): Builder => 
                        $query->where('role_user.expires_at', '<=', now())
                    )
                    ->label('Expired Only'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expires At')
                            ->native(false)
                            ->timezone('Europe/Berlin')
                            ->helperText('Leave empty for permanent assignment'),
                        Forms\Components\Select::make('assigned_by')
                            ->label('Assignment Type')
                            ->options([
                                'manual' => 'Manual',
                                'system' => 'System',
                            ])
                            ->default('manual')
                            ->required(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['assigned_at'] = now();
                        return $data;
                    })
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('User assigned')
                            ->body('The user has been assigned to this role.')
                    ),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('User removed')
                            ->body('The user has been removed from this role.')
                    ),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}