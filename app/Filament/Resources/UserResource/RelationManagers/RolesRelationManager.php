<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('role_id')
                    ->label('Role')
                    ->options(Role::ordered()->pluck('name', 'id'))
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Role')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\ColorColumn::make('chat_color')
                    ->label('Chat Color')
                    ->copyable()
                    ->copyMessage('Color copied'),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state >= 100 => 'danger',
                        $state >= 90 => 'warning',
                        $state >= 50 => 'info',
                        default => 'gray'
                    }),
                Tables\Columns\BadgeColumn::make('pivot.assigned_by')
                    ->label('Assigned By')
                    ->colors([
                        'success' => 'manual',
                        'warning' => 'login',
                        'info' => 'system',
                    ]),
                Tables\Columns\TextColumn::make('pivot.assigned_at')
                    ->label('Assigned')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never')
                    ->color(fn ($state) => $state && $state < now() ? 'danger' : null),
                Tables\Columns\ToggleColumn::make('assigned_at_login')
                    ->label('Login Sync')
                    ->disabled(),
                Tables\Columns\ToggleColumn::make('is_staff')
                    ->label('Staff')
                    ->disabled(),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->query(fn ($query) => 
                        $query->where(function ($q) {
                            $q->whereNull('role_user.expires_at')
                              ->orWhere('role_user.expires_at', '>', now());
                        })
                    )
                    ->label('Active Only')
                    ->default(),
                Tables\Filters\Filter::make('expired')
                    ->query(fn ($query) => 
                        $query->where('role_user.expires_at', '<=', now())
                    )
                    ->label('Expired Only'),
                Tables\Filters\SelectFilter::make('assigned_by')
                    ->options([
                        'manual' => 'Manual',
                        'login' => 'Login',
                        'system' => 'System',
                    ])
                    ->attribute('pivot.assigned_by'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        Forms\Components\Select::make('recordId')
                            ->label('Role')
                            ->options(Role::ordered()->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->placeholder('Select a role'),
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
                            ->title('Role assigned')
                            ->body('The role has been assigned to the user.')
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('extend')
                    ->icon('heroicon-o-clock')
                    ->label('Extend')
                    ->form([
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('New Expiry Date')
                            ->native(false)
                            ->timezone('Europe/Berlin')
                            ->required()
                            ->afterOrEqual('now'),
                    ])
                    ->action(function ($record, array $data) {
                        $this->ownerRecord->roles()->updateExistingPivot($record->id, [
                            'expires_at' => $data['expires_at'],
                        ]);
                        
                        Notification::make()
                            ->success()
                            ->title('Role extended')
                            ->body('The role expiry has been updated.')
                            ->send();
                    })
                    ->visible(fn ($record) => $record->pivot->expires_at !== null),
                Tables\Actions\DetachAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Role removed')
                            ->body('The role has been removed from the user.')
                    )
                    ->modalHeading('Remove Role')
                    ->modalDescription('Are you sure you want to remove this role from the user?'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()
                        ->modalHeading('Remove Selected Roles')
                        ->modalDescription('Are you sure you want to remove the selected roles from this user?'),
                ]),
            ])
            ->defaultSort('priority', 'desc')
            ->paginated([10, 25, 50]);
    }
}