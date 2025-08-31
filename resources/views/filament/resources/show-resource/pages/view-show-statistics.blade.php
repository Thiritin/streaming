<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Summary Statistics --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-filament::card>
                <div class="text-sm font-medium text-gray-500">Current Viewers</div>
                <div class="mt-2 text-3xl font-bold text-primary-600">
                    {{ number_format($statistics['current_viewers']) }}
                </div>
                @if($show->status === 'live')
                    <div class="mt-1 text-xs text-green-600">● Live</div>
                @endif
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm font-medium text-gray-500">Peak Viewers</div>
                <div class="mt-2 text-3xl font-bold text-primary-600">
                    {{ number_format($statistics['peak_viewers']) }}
                </div>
                <div class="mt-1 text-xs text-gray-500">All-time high</div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm font-medium text-gray-500">Average Viewers</div>
                <div class="mt-2 text-3xl font-bold text-primary-600">
                    {{ number_format($statistics['average_viewers']) }}
                </div>
                <div class="mt-1 text-xs text-gray-500">During broadcast</div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm font-medium text-gray-500">Total Unique Viewers</div>
                <div class="mt-2 text-3xl font-bold text-primary-600">
                    {{ number_format($statistics['total_unique_viewers']) }}
                </div>
                <div class="mt-1 text-xs text-gray-500">Distinct users</div>
            </x-filament::card>
        </div>

        {{-- Stream Duration Info --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Broadcast Information</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-sm font-medium text-gray-500">Scheduled Start</dt>
                        <dd class="text-sm text-gray-900">{{ $show->scheduled_start->format('M j, Y H:i') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm font-medium text-gray-500">Scheduled End</dt>
                        <dd class="text-sm text-gray-900">{{ $show->scheduled_end->format('M j, Y H:i') }}</dd>
                    </div>
                    @if($show->actual_start)
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Actual Start</dt>
                            <dd class="text-sm text-gray-900">{{ $show->actual_start->format('M j, Y H:i') }}</dd>
                        </div>
                    @endif
                    @if($show->actual_end)
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Actual End</dt>
                            <dd class="text-sm text-gray-900">{{ $show->actual_end->format('M j, Y H:i') }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-sm font-medium text-gray-500">Total Duration</dt>
                        <dd class="text-sm text-gray-900">{{ floor($statistics['total_duration_minutes'] / 60) }}h {{ $statistics['total_duration_minutes'] % 60 }}m</dd>
                    </div>
                </dl>
            </x-filament::card>

            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Engagement Metrics</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-sm font-medium text-gray-500">Total View Minutes</dt>
                        <dd class="text-sm text-gray-900">{{ number_format($statistics['total_view_minutes']) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm font-medium text-gray-500">Min Viewers</dt>
                        <dd class="text-sm text-gray-900">{{ number_format($statistics['min_viewers']) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm font-medium text-gray-500">Status</dt>
                        <dd class="text-sm">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $show->status === 'live' ? 'bg-green-100 text-green-800' : '' }}
                                {{ $show->status === 'ended' ? 'bg-gray-100 text-gray-800' : '' }}
                                {{ $show->status === 'scheduled' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                {{ $show->status === 'cancelled' ? 'bg-red-100 text-red-800' : '' }}
                            ">
                                {{ ucfirst($show->status) }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </x-filament::card>
        </div>

        {{-- Viewer Chart --}}
        @if(count($statistics['time_series']) > 0)
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Viewer Count Over Time</h3>
                <div id="viewer-chart" style="height: 400px;"></div>
            </x-filament::card>
        @endif

        {{-- Hourly Statistics Table --}}
        @if(count($statistics['hourly_stats']) > 0)
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Hourly Statistics</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hour</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Viewers</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Peak Viewers</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unique Viewers</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($statistics['hourly_stats'] as $hourStat)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ \Carbon\Carbon::parse($hourStat->hour)->format('M j, H:00') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ number_format(round($hourStat->avg_viewers)) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ number_format($hourStat->peak_viewers) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ number_format($hourStat->unique_viewers) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::card>
        @endif

        {{-- Real-time Stats (for live shows) --}}
        @if($realtimeStats && $show->status === 'live')
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Real-time Activity (Last 5 Minutes)</h3>
                <div id="realtime-chart" style="height: 200px;"></div>
            </x-filament::card>
        @endif
    </div>

    @if(count($statistics['time_series']) > 0)
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Main viewer chart
                    const viewerData = @json($statistics['time_series']);
                    
                    const options = {
                        series: [{
                            name: 'Viewers',
                            data: viewerData.map(d => ({
                                x: new Date(d.time),
                                y: d.viewers
                            }))
                        }, {
                            name: 'Unique Viewers',
                            data: viewerData.map(d => ({
                                x: new Date(d.time),
                                y: d.unique
                            }))
                        }],
                        chart: {
                            type: 'area',
                            height: 400,
                            zoom: {
                                enabled: true
                            }
                        },
                        dataLabels: {
                            enabled: false
                        },
                        stroke: {
                            curve: 'smooth',
                            width: 2
                        },
                        xaxis: {
                            type: 'datetime',
                            labels: {
                                datetimeFormatter: {
                                    hour: 'HH:mm'
                                }
                            }
                        },
                        yaxis: {
                            title: {
                                text: 'Viewer Count'
                            },
                            min: 0
                        },
                        tooltip: {
                            x: {
                                format: 'dd MMM HH:mm'
                            }
                        },
                        fill: {
                            type: 'gradient',
                            gradient: {
                                shadeIntensity: 1,
                                opacityFrom: 0.7,
                                opacityTo: 0.3,
                                stops: [0, 100]
                            }
                        },
                        colors: ['#10b981', '#3b82f6']
                    };

                    const chart = new ApexCharts(document.querySelector("#viewer-chart"), options);
                    chart.render();

                    @if($realtimeStats && $show->status === 'live')
                        // Real-time chart for live shows
                        const realtimeData = @json($realtimeStats['trend']);
                        
                        const realtimeOptions = {
                            series: [{
                                name: 'Current Viewers',
                                data: realtimeData.map(d => ({
                                    x: d.time,
                                    y: d.count
                                }))
                            }],
                            chart: {
                                type: 'line',
                                height: 200,
                                animations: {
                                    enabled: true,
                                    easing: 'linear',
                                    dynamicAnimation: {
                                        speed: 1000
                                    }
                                },
                                toolbar: {
                                    show: false
                                }
                            },
                            stroke: {
                                curve: 'smooth',
                                width: 3
                            },
                            xaxis: {
                                type: 'category',
                                labels: {
                                    show: true,
                                    rotate: -45
                                }
                            },
                            yaxis: {
                                title: {
                                    text: 'Viewers'
                                },
                                min: 0
                            },
                            colors: ['#ef4444']
                        };

                        const realtimeChart = new ApexCharts(document.querySelector("#realtime-chart"), realtimeOptions);
                        realtimeChart.render();
                    @endif
                });
            </script>
        @endpush
    @endif
</x-filament-panels::page>
