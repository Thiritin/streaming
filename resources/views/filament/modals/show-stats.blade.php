<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div class="text-center">
            <div class="text-2xl font-bold">{{ $show->viewer_count }}</div>
            <div class="text-sm text-gray-500">Current Viewers</div>
        </div>
        <div class="text-center">
            <div class="text-2xl font-bold">{{ $show->peak_viewer_count }}</div>
            <div class="text-sm text-gray-500">Peak Viewers</div>
        </div>
    </div>

    @if($show->actual_start)
        <div class="grid grid-cols-2 gap-4">
            <div class="text-center">
                <div class="text-lg">{{ $show->actual_start->format('H:i') }}</div>
                <div class="text-sm text-gray-500">Started</div>
            </div>
            <div class="text-center">
                <div class="text-lg">{{ $show->actual_end ? $show->actual_end->format('H:i') : 'Ongoing' }}</div>
                <div class="text-sm text-gray-500">{{ $show->actual_end ? 'Ended' : 'Live' }}</div>
            </div>
        </div>
        
        <div class="text-center">
            <div class="text-lg">{{ $show->formatted_duration }}</div>
            <div class="text-sm text-gray-500">Duration</div>
        </div>
    @endif

    @if($show->source)
        <div class="pt-4 border-t">
            <div class="text-sm font-medium mb-2">Source Details</div>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <div>Source:</div>
                <div class="font-medium">{{ $show->source->name }}</div>
                
                <div>Status:</div>
                <div>
                    @if($show->source->status === \App\Enum\SourceStatusEnum::ONLINE)
                        <span class="text-green-600">Online</span>
                    @else
                        <span class="text-gray-500">Offline</span>
                    @endif
                </div>
                
                @if($show->source->activeViewers)
                    <div>Active Sessions:</div>
                    <div class="font-medium">{{ $show->source->activeViewers()->count() }}</div>
                @endif
            </div>
        </div>
    @endif

    @if($show->tags && count($show->tags) > 0)
        <div class="pt-4 border-t">
            <div class="text-sm font-medium mb-2">Tags</div>
            <div class="flex flex-wrap gap-2">
                @foreach($show->tags as $tag)
                    <span class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-700 rounded">{{ $tag }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>