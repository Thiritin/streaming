<?php

namespace App\Http\Controllers;

use App\Enum\StreamStatusEnum;
use App\Models\Client;
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
            ->live()
            ->orderBy('viewer_count', 'desc')
            ->orderBy('priority', 'desc')
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
                    'is_featured' => $show->is_featured,
                    'started_at' => $show->actual_start ?? $show->scheduled_start,
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
                    'is_featured' => $show->is_featured,
                ];
            });

        return Inertia::render('ShowsGrid', [
            'liveShows' => $liveShows,
            'upcomingShows' => $upcomingShows,
            'currentTime' => now()->toIso8601String(),
        ]);
    }

    public function external(Request $request, Show $show)
    {
        // Load show with source relationship
        $show->load('source');

        // Check if user can watch this show
        if (! $show->canWatch() && ! $show->isLive()) {
            return redirect()->route('shows.grid')
                ->with('error', 'This show is not available for viewing');
        }

        // Get HLS URLs for the show
        $hlsUrls = $show->getHlsUrls();

        return Inertia::render('ExternalStream', [
            'show' => [
                'id' => $show->id,
                'title' => $show->title,
                'slug' => $show->slug,
                'description' => $show->description,
                'source' => $show->source ? $show->source->name : null,
                'status' => $show->status,
                'can_watch' => $show->canWatch(),
                'hls_urls' => $hlsUrls,
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
        if (! $show->canWatch() && ! $show->isLive()) {
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

        // Get HLS URLs from the selected show
        $hlsUrls = $show->getHlsUrls();

        return Inertia::render('ShowPlayer', [
            'initialProvisioning' => false,
            'currentShow' => [
                'id' => $show->id,
                'title' => $show->title,
                'slug' => $show->slug,
                'description' => $show->description,
                'source' => $show->source ? $show->source->name : null,
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
            'initialHlsUrls' => $hlsUrls,
            'initialStatus' => $show->isLive() ? 'online' : \Cache::get('stream.status', static fn () => StreamStatusEnum::OFFLINE->value),
            'initialListeners' => $show->viewer_count ?? StreamInfoService::getUserCount(),
            'initialOtherDevice' => Client::where('user_id', $user->id)
                ->connected()
                ->where('client', '=', 'vlc')
                ->exists(),
            'chatMessages' => array_values(Message::with('user')
                ->where('is_command', false)
                ->orWhere(fn ($q) => $q->where('is_command', true)->where('user_id',
                    $user->id)) // show users own commands
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->reverse()
                ->map(fn (Message $message) => [
                    'id' => $message->id,
                    'message' => $message->message,
                    'is_command' => (bool) $message->is_command,
                    'name' => $message->user->name ?? 'System',
                    'role' => $message->user?->role,
                    'time' => $message->created_at->format('H:i'),
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
