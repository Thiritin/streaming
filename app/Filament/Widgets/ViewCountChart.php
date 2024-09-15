<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\ViewCount;
use Carbon\Carbon;
use Filament\Widgets\LineChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class ViewCountChart extends LineChartWidget
{
    protected static ?string $heading = 'Chart';
    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return "View Count";
    }

    protected function getData(): array
    {
            $days = [
                '02-09-2023' => 'Saturday',
                '03-09-2023' => 'Sunday',
                '04-09-2023' => 'Monday',
                '05-09-2023' => 'Tuesday',
                '06-09-2023' => 'Wednesday',
                '07-09-2023' => 'Thursday',
                '08-09-2023' => 'Friday',
            ];
            $datalist = [];
            foreach ($days as $k => $v) {
                $model = Trend::model(ViewCount::class)
                    ->between(
                        start: \Illuminate\Support\Carbon::parse($k),
                        end: \Illuminate\Support\Carbon::parse($k)->endOfDay(),
                    )
                    ->perHour()
                    ->average('count');
                $datalist[] = [
                    'label' => $v,
                    'data' => $model->map(fn(TrendValue $value) => $value->aggregate)
                ];
            }
            return [
                'datasets' => $datalist,
                'labels' => [
                    "00:00",
                    "01:00",
                    "02:00",
                    "03:00",
                    "04:00",
                    "05:00",
                    "06:00",
                    "07:00",
                    "08:00",
                    "09:00",
                    "10:00",
                    "11:00",
                    "12:00",
                    "13:00",
                    "14:00",
                    "15:00",
                    "16:00",
                    "17:00",
                    "18:00",
                    "19:00",
                    "20:00",
                    "21:00",
                    "22:00",
                    "23:00",
                ],
            ];
    }
}

