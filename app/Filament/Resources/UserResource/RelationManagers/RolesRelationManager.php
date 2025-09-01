<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

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
                    ->color(fn ($state) => match (true) {
                        $state >= 100 => 'danger',
                        $state >= 90 => 'warning',
                        $state >= 50 => 'info',
                        default => 'gray'
                    }),
                Tables\Columns\ToggleColumn::make('assigned_at_login')
                    ->label('Login Sync')
                    ->disabled(),
            ])
            ->filters([])
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
                    ])
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Role assigned')
                            ->body('The role has been assigned to the user.')
                    ),
            ])
            ->actions([
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
