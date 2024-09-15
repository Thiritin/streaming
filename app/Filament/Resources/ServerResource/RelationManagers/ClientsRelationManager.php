<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use App\Filament\Resources\ClientResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientsRelationManager extends RelationManager
{
    protected static string $relationship = 'clients';

    protected static ?string $recordTitleAttribute = 'client_id';

    public function form(Form $form): Form
    {
        return ClientResource::form($form);
    }

    public function table(Table $table): Table
    {
        return ClientResource::table($table);
    }
}
