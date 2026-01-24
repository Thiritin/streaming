<?php

namespace App\Http\Controllers;

use App\Enum\StreamStatusEnum;
use App\Models\Message;
use App\Models\Recording;
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

        // Get live shows (filtered by access)
        $liveShows = Show::with('source')
            ->accessibleBy($user)
            ->where('shows.status', 'live')
            ->leftJoin('sources', 'shows.source_id', '=', 'sources.id')
            ->orderBy('sources.priority', 'desc')
            ->orderBy('shows.viewer_count', 'desc')
            ->select('shows.*')
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
                    'viewer_count' => $show->viewer_count,
                    'started_at' => $show->actual_start ?? $show->scheduled_start,
                    'hls_url' => $show->getHlsUrl(),
                    'is_restricted' => $show->hasAccessRestriction(),
                ];
            });

        // Get shows that should have started but haven't (starting soon)
        $startingSoonShows = Show::with('source')
            ->accessibleBy($user)
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
                    'is_restricted' => $show->hasAccessRestriction(),
                ];
            });

        // Get upcoming shows (next 24 hours)
        $upcomingShows = Show::with('source')
            ->accessibleBy($user)
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
                    'is_restricted' => $show->hasAccessRestriction(),
                ];
            });

        // Get popular recordings (prefer latest year, then by views)
        // First, find the most recent year with recordings
        $latestYear = Recording::accessibleBy($user)
            ->where('is_published', true)
            ->selectRaw('YEAR(date) as year')
            ->orderBy('year', 'desc')
            ->first()
            ?->year;

        $popularRecordings = collect();
        if ($latestYear) {
            $popularRecordings = Recording::accessibleBy($user)
                ->where('is_published', true)
                ->whereYear('date', $latestYear)
                ->orderBy('views', 'desc')
                ->limit(8)
                ->get()
                ->map(function ($recording) {
                    return [
                        'id' => $recording->id,
                        'title' => $recording->title,
                        'slug' => $recording->slug,
                        'description' => $recording->description,
                        'date' => $recording->date,
                        'duration' => $recording->duration,
                        'formatted_duration' => $recording->formatted_duration,
                        'thumbnail_url' => $recording->thumbnail_url,
                        'views' => $recording->views,
                        'is_restricted' => $recording->hasAccessRestriction(),
                    ];
                });
        }

        return Inertia::render('ShowsGrid', [
            'liveShows' => $liveShows,
            'startingSoonShows' => $startingSoonShows,
            'upcomingShows' => $upcomingShows,
            'popularRecordings' => $popularRecordings,
            'currentTime' => now()->toIso8601String(),
        ]);
    }

    public function external(Request $request, Show $show)
    {
        // Load show with source relationship
        $show->load('source');

        /** @var User $user */
        $user = Auth::user();

        // Check access restrictions
        if (! $show->canBeAccessedBy($user)) {
            return redirect()->route('shows.grid')
                ->with('error', 'You do not have permission to view this show');
        }

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
            $hlsUrl .= '?streamkey='.$user->streamkey;
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

        // Check access restrictions
        if (! $show->canBeAccessedBy($user)) {
            return redirect()->route('shows.grid')
                ->with('error', 'You do not have permission to view this show');
        }

        // Check if user can watch this show
        // Allow access to scheduled, live, and recently ended shows
        if (! in_array($show->status, ['scheduled', 'live', 'ended', 'cancelled'])) {
            return redirect()->route('shows.grid')
                ->with('error', 'This show is not available for viewing');
        }

        // Get all available shows for switching (filtered by access)
        $availableShows = Show::with('source')
            ->accessibleBy($user)
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
                    'is_restricted' => $s->hasAccessRestriction(),
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
            'sourceId' => $show->source_id,
            'chatMessages' => array_values(Message::with('user')
                ->where('source_id', $show->source_id)
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
                    'source_id' => $message->source_id,
                ])->toArray()),
            'rateLimit' => [
                'maxTries' => \Cache::get('chat.maxTries', static fn () => config('chat.default.maxTries')),
                'rateDecay' => \Cache::get('chat.rateDecay', static fn () => config('chat.default.rateDecay')),
                'slowMode' => \Cache::get('chat.slowMode', static fn () => config('chat.default.slowMode')),
                'secondsLeft' => (! $user->isStaff()) ? RateLimiter::availableIn('send-message:'.$user->id) : 0,
            ],
        ]);
    }

    /**
     * Pop-out chat window
     */
    public function chat(Request $request, Show $show)
    {
        /** @var User $user */
        $user = Auth::user();

        // Load show with source relationship
        $show->load('source');

        // Check access restrictions
        if (! $show->canBeAccessedBy($user)) {
            abort(403, 'You do not have permission to view this chat');
        }

        // Check if show is in a valid state for chat
        if (! in_array($show->status, ['scheduled', 'live'])) {
            abort(404, 'Chat is not available for this show');
        }

        return Inertia::render('ChatPopout', [
            'show' => [
                'id' => $show->id,
                'title' => $show->title,
                'slug' => $show->slug,
                'status' => $show->status,
            ],
            'sourceId' => $show->source_id,
            'chatMessages' => array_values(Message::with('user')
                ->where('source_id', $show->source_id)
                ->where(function ($query) use ($user) {
                    $query->where('is_command', false)
                        ->orWhere('type', 'announcement')
                        ->orWhere('type', 'system')
                        ->orWhere(fn ($q) => $q->where('is_command', true)->where('user_id', $user->id));
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
                    'source_id' => $message->source_id,
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
