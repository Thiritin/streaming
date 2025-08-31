<?php

namespace App\Filament\Resources\ShowResource\Pages;

use App\Filament\Resources\ShowResource;
use App\Models\Show;
use App\Services\ShowStatisticsService;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewShowStatistics extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ShowResource::class;

    protected static string $view = 'filament.resources.show-resource.pages.view-show-statistics';

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string | Htmlable
    {
        return 'Statistics for ' . $this->record->title;
    }

    protected function getViewData(): array
    {
        $service = new ShowStatisticsService();
        $statistics = $service->getShowStatistics($this->record);
        
        return [
            'show' => $this->record,
            'statistics' => $statistics,
            'realtimeStats' => $this->record->status === 'live' ? $service->getRealtimeStats($this->record) : null,
        ];
    }
}