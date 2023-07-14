<?php

namespace App\Filament\Pages;

use App\Enum\StreamStatusEnum;
use App\Events\StreamStatusEvent;
use App\Filament\Widgets\Capacity;
use App\Filament\Widgets\ServerActive;
use App\Filament\Widgets\ServerUserActive;
use Filament\Pages\Actions\Action;
use Filament\Pages\Actions\ActionGroup;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Event;

class Stream extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationGroup = "Mission Control Center";
    protected static ?string $navigationLabel = "Stream Settings";

    protected static string $view = 'filament.pages.stream';

    protected function getActions(): array
    {
        return [
            Action::make('set_offline')->action(fn() => event(new StreamStatusEvent(StreamStatusEnum::OFFLINE)))->label('Set Stream Offline')->requiresConfirmation(),
            Action::make('set_online')->action(fn() => event(new StreamStatusEvent(StreamStatusEnum::ONLINE)))->label('Set Stream Online')->requiresConfirmation(),
            Action::make('set_issue')->action(fn() => event(new StreamStatusEvent(StreamStatusEnum::TECHNICAL_ISSUE)))->label('Set Stream Technical Issue')->requiresConfirmation(),
            Action::make('set_pending')->action(fn() => event(new StreamStatusEvent(StreamStatusEnum::STARTING_SOON)))->label('Set Stream Starting Soon')->requiresConfirmation(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ServerUserActive::class,
            ServerActive::class,
            Capacity::class
        ];
    }


}
