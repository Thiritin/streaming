<?php

namespace App\Filament\Pages;

use App\Enum\StreamStatusEnum;
use App\Events\StreamStatusEvent;
use App\Filament\Widgets\Capacity;
use App\Filament\Widgets\ClientsActive;
use App\Filament\Widgets\ServerActive;
use App\Filament\Widgets\ViewCountChart;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;

class Stream extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationGroup = 'Mission Control Center';

    protected static ?string $navigationLabel = 'Stream Settings';

    protected static string $view = 'filament.pages.stream';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('set_pending')
                ->action(fn () => event(new StreamStatusEvent(StreamStatusEnum::STARTING_SOON)))
                ->label('Set Stream Starting Soon (Start Servers)')
                ->tooltip('Will start servers, takes around 6 minutes.')
                ->requiresConfirmation(),
            Action::make('set_online')
                ->action(fn () => event(new StreamStatusEvent(StreamStatusEnum::ONLINE)))
                ->label('Set Stream Online')
                ->tooltip('Set this after you started the stream in obs for the first time.')
                ->requiresConfirmation(),
            Action::make('set_issue')
                ->action(fn () => event(new StreamStatusEvent(StreamStatusEnum::TECHNICAL_ISSUE)))
                ->label('Set Stream Technical Issue')
                ->tooltip('Set this if you have technical issues with the stream. Will automatically activate upon stream disconnect.')
                ->requiresConfirmation(),
            Action::make('set_offline')
                ->requiresConfirmation()
                ->action(fn () => event(new StreamStatusEvent(StreamStatusEnum::OFFLINE)))
                ->tooltip('This sets the stream fully offline and deletes ALL Servers.')
                ->label('Set Stream Offline (Delete Servers)')
                ->color('danger'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ClientsActive::class,
            ServerActive::class,
            Capacity::class,
            ViewCountChart::class,
        ];
    }
}
