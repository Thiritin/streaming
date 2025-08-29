<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Filament\Resources\RoleResource\RelationManagers;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    
    protected static ?string $navigationGroup = 'User Management';
    
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Role Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $state, Forms\Set $set) => 
                                $set('slug', Str::slug($state))
                            ),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Used for system identification'),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Section::make('Chat Appearance')
                    ->schema([
                        ColorPicker::make('chat_color')
                            ->label('Chat Color')
                            ->helperText('Color displayed in chat for users with this role')
                            ->default('#808080'),
                        TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->helperText('Higher priority roles are displayed first (100 for admin, 90 for moderator, etc.)'),
                        Toggle::make('is_visible')
                            ->label('Show in Chat')
                            ->default(true)
                            ->helperText('Whether this role\'s color is visible in chat'),
                    ])
                    ->columns(3),
                
                Section::make('Settings')
                    ->schema([
                        Toggle::make('assigned_at_login')
                            ->label('Assigned at Login')
                            ->default(true)
                            ->helperText('If enabled, this role is synced from the registration system at login. If disabled, role persists through logins.'),
                        Toggle::make('is_staff')
                            ->label('Staff Role')
                            ->default(false)
                            ->helperText('Mark this for admin/moderator roles'),
                        TagsInput::make('permissions')
                            ->label('Permissions')
                            ->separator(',')
                            ->suggestions([
                                'filament.access',
                                'admin.access',
                                'chat.moderate',
                                'chat.delete',
                                'chat.timeout',
                                'chat.slowmode',
                                'stream.manage',
                                'user.manage',
                            ])
                            ->helperText('System permissions for this role')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Section::make('Additional Configuration')
                    ->schema([
                        KeyValue::make('metadata')
                            ->label('Metadata')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->addButtonLabel('Add Metadata'),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                ColorColumn::make('chat_color')
                    ->label('Chat Color')
                    ->copyable()
                    ->copyMessage('Color copied')
                    ->copyMessageDuration(1500),
                TextColumn::make('priority')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state >= 100 => 'danger',
                        $state >= 90 => 'warning',
                        $state >= 50 => 'info',
                        default => 'gray'
                    }),
                ToggleColumn::make('assigned_at_login')
                    ->label('Login Sync')
                    ->onColor('success')
                    ->offColor('gray')
                    ->afterStateUpdated(function ($record, $state) {
                        Notification::make()
                            ->title($state ? 'Role will be synced at login' : 'Role will persist through logins')
                            ->success()
                            ->send();
                    }),
                ToggleColumn::make('is_staff')
                    ->label('Staff')
                    ->onColor('warning')
                    ->offColor('gray'),
                ToggleColumn::make('is_visible')
                    ->label('Visible')
                    ->onColor('success')
                    ->offColor('danger'),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->sortable()
                    ->badge()
                    ->color('success'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_staff')
                    ->label('Staff Roles')
                    ->boolean()
                    ->trueLabel('Staff only')
                    ->falseLabel('Non-staff only')
                    ->placeholder('All roles'),
                Tables\Filters\TernaryFilter::make('assigned_at_login')
                    ->label('Login Assignment')
                    ->boolean()
                    ->trueLabel('Login-synced only')
                    ->falseLabel('Manually assigned only')
                    ->placeholder('All roles'),
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label('Chat Visibility')
                    ->boolean()
                    ->trueLabel('Visible only')
                    ->falseLabel('Hidden only')
                    ->placeholder('All roles'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Role $record) {
                        if ($record->users()->count() > 0) {
                            Notification::make()
                                ->title('Cannot delete role')
                                ->body('This role has assigned users. Remove all users before deleting.')
                                ->danger()
                                ->send();
                            
                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if ($record->users()->count() > 0) {
                                    Notification::make()
                                        ->title('Cannot delete roles')
                                        ->body('One or more roles have assigned users.')
                                        ->danger()
                                        ->send();
                                    
                                    return false;
                                }
                            }
                        }),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('create_default_roles')
                    ->label('Create Default Roles')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn () => Role::count() === 0)
                    ->requiresConfirmation()
                    ->modalHeading('Create Default Roles')
                    ->modalDescription('This will create the default set of roles for the system.')
                    ->modalSubmitActionLabel('Create Roles')
                    ->action(function () {
                        self::createDefaultRoles();
                        Notification::make()
                            ->title('Default roles created')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
    
    protected static function createDefaultRoles(): void
    {
        $roles = [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full system administrator',
                'chat_color' => '#FF0000',
                'priority' => 100,
                'assigned_at_login' => false,
                'is_staff' => true,
                'is_visible' => true,
                'permissions' => ['admin.access', 'filament.access', 'chat.moderate', 'stream.manage', 'user.manage'],
            ],
            [
                'name' => 'Moderator',
                'slug' => 'moderator',
                'description' => 'Chat and stream moderator',
                'chat_color' => '#00FF00',
                'priority' => 90,
                'assigned_at_login' => false,
                'is_staff' => true,
                'is_visible' => true,
                'permissions' => ['filament.access', 'chat.moderate', 'chat.delete', 'chat.timeout', 'chat.slowmode'],
            ],
            [
                'name' => 'Super Sponsor',
                'slug' => 'super-sponsor',
                'description' => 'Super sponsor with special chat color',
                'chat_color' => '#FFD700',
                'priority' => 50,
                'assigned_at_login' => true,
                'is_staff' => false,
                'is_visible' => true,
                'permissions' => [],
            ],
            [
                'name' => 'Sponsor',
                'slug' => 'sponsor',
                'description' => 'Event sponsor with chat color',
                'chat_color' => '#C0C0C0',
                'priority' => 40,
                'assigned_at_login' => true,
                'is_staff' => false,
                'is_visible' => true,
                'permissions' => [],
            ],
            [
                'name' => 'Attendee',
                'slug' => 'attendee',
                'description' => 'Regular event attendee',
                'chat_color' => '#808080',
                'priority' => 10,
                'assigned_at_login' => true,
                'is_staff' => false,
                'is_visible' => false,
                'permissions' => [],
            ],
        ];

        foreach ($roles as $roleData) {
            Role::create($roleData);
        }
    }
}