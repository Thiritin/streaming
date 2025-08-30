<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmoteResource\Pages;
use App\Models\Emote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmoteResource extends Resource
{
    protected static ?string $model = Emote::class;

    protected static ?string $navigationIcon = 'heroicon-o-face-smile';

    protected static ?string $navigationGroup = 'Chat Management';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Emote Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9_]+$/')
                            ->maxLength(20),
                        Forms\Components\FileUpload::make('url')
                            ->label('Emote Image')
                            ->image()
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth(64)
                            ->imageResizeTargetHeight(64)
                            ->disk('s3')
                            ->directory('emotes')
                            ->visibility('public'),
                        Forms\Components\Toggle::make('is_global')
                            ->label('Available for all users')
                            ->helperText('If disabled, only the uploader can use this emote'),
                        Forms\Components\Toggle::make('is_approved')
                            ->label('Approved')
                            ->helperText('Approve this emote for use in chat'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\Select::make('uploaded_by_user_id')
                            ->label('Uploaded By')
                            ->relationship('uploadedBy', 'name')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Select::make('approved_by_user_id')
                            ->label('Approved By')
                            ->relationship('approvedBy', 'name')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\DateTimePicker::make('approved_at')
                            ->label('Approved At')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('usage_count')
                            ->label('Usage Count')
                            ->disabled()
                            ->dehydrated(false)
                            ->numeric(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('url')
                    ->label('Emote')
                    ->size(40)
                    ->circular(false),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn ($state) => ':'.$state.':'),
                Tables\Columns\TextColumn::make('uploadedBy.name')
                    ->label('Uploaded By')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_global')
                    ->label('Global')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_approved')
                    ->label('Approved')
                    ->boolean()
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Usage')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('approval_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending Approval',
                        'approved' => 'Approved',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'pending') {
                            return $query->where('is_approved', false);
                        } elseif ($data['value'] === 'approved') {
                            return $query->where('is_approved', true);
                        }
                    }),
                Tables\Filters\TernaryFilter::make('is_global')
                    ->label('Global'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Emote $record) => ! $record->is_approved)
                    ->requiresConfirmation()
                    ->action(function (Emote $record) {
                        $record->approve(auth()->user());
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Emote $record) => ! $record->is_approved)
                    ->requiresConfirmation()
                    ->modalDescription('This will permanently delete the emote and its image.')
                    ->action(function (Emote $record) {
                        $record->reject();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve_selected')
                    ->label('Approve Selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            if (! $record->is_approved) {
                                $record->approve(auth()->user());
                            }
                        }
                    }),
                Tables\Actions\BulkAction::make('reject_selected')
                    ->label('Reject Selected')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This will permanently delete the selected emotes and their images.')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            if (! $record->is_approved) {
                                $record->reject();
                            }
                        }
                    }),
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmotes::route('/'),
            'create' => Pages\CreateEmote::route('/create'),
            'edit' => Pages\EditEmote::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::$model::pending()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::$model::pending()->count() > 0 ? 'warning' : null;
    }
}
