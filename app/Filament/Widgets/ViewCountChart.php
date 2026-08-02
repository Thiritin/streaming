<?php

namespace App\Filament\Widgets;

use App\Models\ViewCount;
use Filament\Widgets\LineChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class ViewCountChart extends LineChartWidget
{
    protected static ?string $heading = 'Chart';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return 'View Count';
    }

    protected function getData(): array
    {
        // The last seven days, rather than one convention's fixed dates, so the
        // chart stays meaningful for any installation and any year.
        $days = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = \Illuminate\Support\Carbon::today()->subDays($offset);
            $days[] = ['date' => $day, 'label' => $day->format('D d M')];
        }

        $datalist = [];
        foreach ($days as ['date' => $date, 'label' => $v]) {
            $model = Trend::model(ViewCount::class)
                ->between(
                    start: $date->copy()->startOfDay(),
                    end: $date->copy()->endOfDay(),
                )
                ->perHour()
                ->average('count');
            $datalist[] = [
                'label' => $v,
                'data' => $model->map(fn (TrendValue $value) => $value->aggregate),
            ];
        }

        return [
            'datasets' => $datalist,
            'labels' => [
                '00:00',
                '01:00',
                '02:00',
                '03:00',
                '04:00',
                '05:00',
                '06:00',
                '07:00',
                '08:00',
                '09:00',
                '10:00',
                '11:00',
                '12:00',
                '13:00',
                '14:00',
                '15:00',
                '16:00',
                '17:00',
                '18:00',
                '19:00',
                '20:00',
                '21:00',
                '22:00',
                '23:00',
            ],
        ];
    }
}
