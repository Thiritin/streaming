<?php

namespace App\Http\Controllers;

use App\Enum\StreamStatusEnum;
use App\Models\Message;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Services\StreamInfoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class StreamController extends Controller
{
    /**
     * Shows grid - main landing page
     */
    public function index()
    {
        /** @var User $user */
        $user = Auth::user();

        // Get live shows
        $liveShows = Show::with('source')
            ->where('shows.status', 'live')
            ->leftJoin('sources', 'shows.source_id', '=', 'sources.id')
            ->orderBy('sources.priority', 'desc')
            ->orderBy('shows.viewer_count', 'desc')
            ->select('shows.*')
            ->get()
            ->map(function ($show) use ($user) {
                return [
                    'id' => $show->id,
                    'title' => $show->title,
                    'slug' => $show->slug,
                    'description' => $show->description,
                    'source' => $show->source ? $show->source->name : null,
                    'status' => $show->status,
                    'thumbnail_url' => $show->thumbnail_url,
                    'viewer_count' => $show->viewer_count,
                    'started_at' => $show->actual_start ?? $show->scheduled_start,
                    'hls_url' => $show->getHlsUrl(),
                ];
            });

        // Get shows that should have started but haven't (starting soon)
        $startingSoonShows = Show::with('source')
            ->scheduled()
            ->where('scheduled_start', '<=', now())
            ->orderBy('scheduled_start', 'desc')
            ->get()
            ->map(function ($show) {
                return [
                    'id' => $show->id,
                    'title' => $show->title,
                    'slug' => $show->slug,
                    'description' => $show->description,
                    'source' => $show->source ? $show->source->name : null,
                    'status' => 'starting_soon', // Override status to indicate starting soon
                    'thumbnail_url' => $show->thumbnail_url,
                    'scheduled_start' => $show->scheduled_start,
                    'scheduled_end' => $show->scheduled_end,
                ];
            });

        // Get upcoming shows (next 24 hours)
        $upcomingShows = Show::with('source')
            ->scheduled()
            ->where('scheduled_start', '>', now())
            ->where('scheduled_start', '<=', now()->addDay())
            ->orderBy('scheduled_start')
            ->get()
            ->map(function ($show) {
                return [
                    'id' => $show->id,
                    'title' => $show->title,
                    'slug' => $show->slug,
                    'description' => $show->description,
                    'source' => $show->source ? $show->source->name : null,
                    'status' => $show->status,
                    'thumbnail_url' => $show->thumbnail_url,
                    'scheduled_start' => $show->scheduled_start,
                    'scheduled_end' => $show->scheduled_end,
                ];
            });

        return Inertia::render('ShowsGrid', [
            'liveShows' => $liveShows,
            'startingSoonShows' => $startingSoonShows,
            'upcomingShows' => $upcomingShows,
            'currentTime' => now()->toIso8601String(),
        ]);
    }

    public function external(Request $request, Show $show)
    {
        // Load show with source relationship
        $show->load('source');

        // Check if user can watch this show
        // Allow access to scheduled, live, and recently ended shows
        if (! in_array($show->status, ['scheduled', 'live', 'ended', 'cancelled'])) {
            return redirect()->route('shows.grid')
                ->with('error', 'This show is not available for viewing');
        }

        // Get HLS URL for the show
        $user = Auth::user();
        $hlsUrl = $show->getHlsUrl();
        
        // Add streamkey to the URL if user has one
        if ($user && $user->streamkey) {
            $hlsUrl .= '?streamkey=' . $user->streamkey;
        }

        return Inertia::render('ExternalStream', [
            'show' => [
                'id' => $show->id,
                'title' => $show->title,
                'slug' => $show->slug,
                'description' => $show->description,
                'source' => $show->source ? $show->source->name : null,
                'status' => $show->status,
                'can_watch' => $show->canWatch(),
                'hls_url' => $hlsUrl,
            ],
        ]);
    }

    public function show(Request $request, Show $show)
    {
        /** @var User $user */
        $user = Auth::user();

        // Load show with source relationship
        $show->load('source');

        // Check if user can watch this show
        // Allow access to scheduled, live, and recently ended shows
        if (! in_array($show->status, ['scheduled', 'live', 'ended', 'cancelled'])) {
            return redirect()->route('shows.grid')
                ->with('error', 'This show is not available for viewing');
        }

        // Get all available shows for switching
        $availableShows = Show::with('source')
            ->where('status', '!=', 'ended')
            ->orderBy('scheduled_start')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'title' => $s->title,
                    'slug' => $s->slug,
                    'source' => $s->source ? $s->source->name : null,
                    'status' => $s->status,
                    'scheduled_start' => $s->scheduled_start,
                    'can_watch' => $s->canWatch(),
                ];
            });

        // Get HLS URL from the selected show
        $hlsUrl = $show->getHlsUrl();

        return Inertia::render('ShowPlayer', [
            'initialProvisioning' => false,
            'currentShow' => [
                'id' => $show->id,
                'title' => $show->title,
                'slug' => $show->slug,
                'description' => $show->description,
                'source' => $show->source ? [
                    'id' => $show->source->id,
                    'name' => $show->source->name,
                    'status' => $show->source->status->value,
                ] : null,
                'source_id' => $show->source_id,
                'status' => $show->status,
                'thumbnail_url' => $show->thumbnail_url,
                'viewer_count' => $show->viewer_count,
                'scheduled_start' => $show->scheduled_start,
                'scheduled_end' => $show->scheduled_end,
                'actual_start' => $show->actual_start,
                'actual_end' => $show->actual_end,
            ],
            'availableShows' => $availableShows,
            'initialHlsUrl' => $hlsUrl,
            'initialStatus' => $show->isLive() ? 'online' : \Cache::get('stream.status', static fn () => StreamStatusEnum::OFFLINE->value),
            'initialListeners' => $show->viewer_count ?? StreamInfoService::getUserCount(),
            'initialOtherDevice' => false, // This feature has been removed with Client model
            'chatMessages' => array_values(Message::with('user')
                ->where(function ($query) use ($user) {
                    $query->where('is_command', false)
                          ->orWhere('type', 'announcement')
                          ->orWhere('type', 'system')
                          ->orWhere(fn ($q) => $q->where('is_command', true)->where('user_id', $user->id)); // show users own commands
                })
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->reverse()
                ->map(fn (Message $message) => [
                    'id' => $message->id,
                    'message' => $message->message,
                    'is_command' => (bool) $message->is_command,
                    'name' => $message->user->name ?? null,
                    'role' => $message->user?->role,
                    'chat_color' => $message->user?->chat_color,
                    'time' => $message->created_at->format('H:i'),
                    'type' => $message->type,
                    'priority' => $message->priority,
                    'metadata' => $message->metadata,
                ])->toArray()),
            'rateLimit' => [
                'maxTries' => \Cache::get('chat.maxTries', static fn () => config('chat.default.maxTries')),
                'rateDecay' => \Cache::get('chat.rateDecay', static fn () => config('chat.default.rateDecay')),
                'slowMode' => \Cache::get('chat.slowMode', static fn () => config('chat.default.slowMode')),
                'secondsLeft' => (! $user->isStaff()) ? RateLimiter::availableIn('send-message:'.$user->id) : 0,
            ],
        ]);
    }
}
