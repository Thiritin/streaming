<?php

namespace App\Http\Controllers;

use App\Enum\StreamStatusEnum;
use App\Models\Message;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Services\Chat\ChatSettingsService;
use App\Services\Chat\MessagePresenter;
use App\Services\PlaybackTokenService;
use App\Services\StreamInfoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class StreamController extends Controller
{
    /**
     * Backlog plus live chat state for a source, shared by the player and the popout.
     *
     * A guest (only possible when login is optional) reads along but has no
     * limits, timeout or ban of their own, so those come back empty and the
     * client renders a sign-in prompt in place of the composer.
     *
     * @return array<string, mixed>
     */
    protected function chatProps(?int $sourceId, ?User $user): array
    {
        if (! config('chat.enabled')) {
            return $this->emptyChatProps();
        }

        $messages = Message::with(['user', 'replyTo.user'])
            ->visibleTo($user)
            ->where('source_id', $sourceId)
            ->orderByDesc('id')
            ->limit((int) config('chat.history.initial', 60))
            ->get()
            ->reverse();

        $settings = app(ChatSettingsService::class)->all($sourceId);

        if (! $user) {
            return [
                'chatMessages' => app(MessagePresenter::class)->presentMany($messages),
                'chatSettings' => $settings,
                'chatState' => [
                    'limits' => [
                        'slow_mode_seconds' => (int) $settings['slow_mode_seconds'],
                        'max_tries' => (int) $settings['max_tries'],
                        'rate_decay' => (int) $settings['rate_decay'],
                        'seconds_left' => 0,
                        'can_bypass' => false,
                    ],
                    'timeout' => null,
                    'ban' => null,
                ],
            ];
        }

        $canBypass = $user->canModerateChat() || $user->hasPermission('chat.ignore.ratelimit');
        $slowMode = (int) $settings['slow_mode_seconds'];
        $timeout = $user->activeTimeout();
        $ban = $user->activeChatBan();

        return [
            'chatMessages' => app(MessagePresenter::class)->presentMany($messages),
            'chatSettings' => $settings,
            'chatState' => [
                'limits' => [
                    'slow_mode_seconds' => $slowMode,
                    'max_tries' => $slowMode > 0 ? 1 : (int) $settings['max_tries'],
                    'rate_decay' => $slowMode > 0 ? $slowMode : (int) $settings['rate_decay'],
                    'seconds_left' => $canBypass ? 0 : RateLimiter::availableIn("send-message:{$user->id}:{$sourceId}"),
                    'can_bypass' => $canBypass,
                ],
                'timeout' => $timeout ? [
                    'seconds_remaining' => (int) now()->diffInSeconds($timeout->expires_at),
                    'reason' => $timeout->reason,
                ] : null,
                'ban' => $ban ? [
                    'permanent' => $ban->isPermanent(),
                    'expires_at' => $ban->expires_at?->toIso8601String(),
                    'reason' => $ban->reason,
                ] : null,
            ],
        ];
    }

    /**
     * What the player gets when chat is switched off, so the page shape stays
     * the same and the client only has to check one flag.
     *
     * @return array<string, mixed>
     */
    protected function emptyChatProps(): array
    {
        return [
            'chatMessages' => [],
            'chatSettings' => app(ChatSettingsService::class)->all(null),
            'chatState' => [
                'limits' => [
                    'slow_mode_seconds' => 0,
                    'max_tries' => 0,
                    'rate_decay' => 0,
                    'seconds_left' => 0,
                    'can_bypass' => false,
                ],
                'timeout' => null,
                'ban' => null,
            ],
        ];
    }

    /**
     * Shows grid - main landing page
     */
    public function index()
    {
        /** @var User|null $user */
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
                    'description_html' => $show->description_html,
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
                    'description_html' => $show->description_html,
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
                    'description_html' => $show->description_html,
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
        // Ordering by `date` and reading the year off the model keeps this working on
        // both MySQL and Postgres; YEAR() is MySQL-only.
        $latestYear = Recording::accessibleBy($user)
            ->where('is_published', true)
            ->orderBy('date', 'desc')
            ->first()
            ?->date
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

        // Archive: everything that already happened, newest first. The browse page
        // shows a slice of it inline so the grid never looks empty between shows;
        // the full list lives on the archive page.
        $archiveRecordings = Recording::accessibleBy($user)
            ->where('is_published', true)
            ->orderBy('date', 'desc')
            ->limit(12)
            ->get()
            ->map(fn ($recording) => $this->mapRecording($recording));

        $archiveTotal = Recording::accessibleBy($user)
            ->where('is_published', true)
            ->count();

        $primarySource = Source::featured();
        $featured = $this->resolveFeaturedShow($user, $primarySource);

        // Channel chips: only sources that actually have something in the grid.
        $channels = $liveShows->concat($startingSoonShows)->concat($upcomingShows)
            ->pluck('source')
            ->filter()
            ->unique()
            ->values();

        return Inertia::render('ShowsGrid', [
            'liveShows' => $liveShows,
            'startingSoonShows' => $startingSoonShows,
            'upcomingShows' => $upcomingShows,
            'popularRecordings' => $popularRecordings,
            'archiveRecordings' => $archiveRecordings,
            'archiveTotal' => $archiveTotal,
            'featured' => $featured,
            'featuredChat' => $this->featuredChatExcerpt($user, $featured),
            'primaryChannel' => $primarySource?->name,
            'channels' => $channels,
            'currentTime' => now()->toIso8601String(),
        ]);
    }

    /**
     * What to point a viewer at when the show they opened is not watchable.
     *
     * An ended or scheduled show keeps its own page rather than redirecting, so the page
     * has to offer somewhere to go. The order matches how an event is actually watched:
     *
     *  1. The primary channel if it is live. It runs for the whole event, so it is almost
     *     always the right answer and is worth promoting over anything else.
     *  2. Otherwise the busiest live show, since something on air beats something later.
     *  3. Otherwise the next scheduled show, so the page still says what is coming.
     *
     * The show being viewed is excluded throughout: promoting a viewer back to the page
     * they are already on is worse than showing nothing.
     */
    private function resolvePromotedShow(?User $user, Show $current): ?array
    {
        $exclude = fn ($query) => $query->where('id', '!=', $current->id);

        $primarySource = Source::featured();

        $show = null;

        if ($primarySource) {
            $show = Show::with('source')
                ->accessibleBy($user)
                ->where($exclude)
                ->where('source_id', $primarySource->id)
                ->where('status', 'live')
                ->orderByDesc('viewer_count')
                ->first();
        }

        $show ??= Show::with('source')
            ->accessibleBy($user)
            ->where($exclude)
            ->where('status', 'live')
            ->orderByDesc('viewer_count')
            ->first();

        $show ??= Show::with('source')
            ->accessibleBy($user)
            ->where($exclude)
            ->scheduled()
            ->where('scheduled_start', '>=', now())
            ->orderBy('scheduled_start')
            ->first();

        if (! $show) {
            return null;
        }

        return [
            'id' => $show->id,
            'title' => $show->title,
            'slug' => $show->slug,
            'source' => $show->source?->name,
            'status' => $show->status,
            'scheduled_start' => $show->scheduled_start,
            'thumbnail_url' => $show->thumbnail_url,
            'viewer_count' => $show->viewer_count,
            'can_watch' => $show->canWatch(),
            // Drives the copy: "watch the main stage now" reads differently from
            // "up next on stage b".
            'is_primary_channel' => $primarySource && $show->source_id === $primarySource->id,
            'is_live' => $show->status === 'live',
        ];
    }

    /**
     * Resolve the featured show for the stage hero.
     *
     * The primary channel (highest source priority, e.g. Prime) always owns the
     * hero: live if it is on air, otherwise its next scheduled show. Only when that
     * channel has nothing at all do we fall back to the busiest live show.
     */
    private function resolveFeaturedShow(?User $user, ?Source $primarySource): ?array
    {
        $show = null;

        if ($primarySource) {
            $show = Show::with('source')
                ->accessibleBy($user)
                ->where('source_id', $primarySource->id)
                ->where('status', 'live')
                ->orderBy('viewer_count', 'desc')
                ->first()
                ?? Show::with('source')
                    ->accessibleBy($user)
                    ->where('source_id', $primarySource->id)
                    ->scheduled()
                    ->where('scheduled_start', '>=', now()->subHours(2))
                    ->orderBy('scheduled_start')
                    ->first();
        }

        $show ??= Show::with('source')
            ->accessibleBy($user)
            ->where('status', 'live')
            ->orderBy('viewer_count', 'desc')
            ->first();

        if (! $show) {
            return null;
        }

        $upNext = Show::with('source')
            ->accessibleBy($user)
            ->where('source_id', $show->source_id)
            ->where('id', '!=', $show->id)
            ->scheduled()
            ->where('scheduled_start', '>=', now())
            ->orderBy('scheduled_start')
            ->first();

        return [
            'id' => $show->id,
            'title' => $show->title,
            'slug' => $show->slug,
            'description' => $show->description,
            'description_html' => $show->description_html,
            'source' => $show->source?->name,
            'source_id' => $show->source_id,
            'status' => $show->status,
            'thumbnail_url' => $show->thumbnail_url,
            'hls_url' => $show->status === 'live' ? $show->getHlsUrl() : null,
            'viewer_count' => $show->viewer_count,
            'started_at' => $show->actual_start ?? $show->scheduled_start,
            'scheduled_start' => $show->scheduled_start,
            'scheduled_end' => $show->scheduled_end,
            'is_restricted' => $show->hasAccessRestriction(),
            'is_primary_channel' => $primarySource && $show->source_id === $primarySource->id,
            'up_next' => $upNext ? [
                'title' => $upNext->title,
                'slug' => $upNext->slug,
                'scheduled_start' => $upNext->scheduled_start,
            ] : null,
        ];
    }

    /**
     * The last few chat lines for the featured channel.
     *
     * Chat is keyed by source, not by show, so the excerpt follows the channel and
     * survives a show ending mid-conversation.
     */
    private function featuredChatExcerpt(?User $user, ?array $featured): array
    {
        if (! config('chat.enabled')) {
            return ['source_id' => null, 'messages' => []];
        }

        if (! $featured || ! ($featured['source_id'] ?? null)) {
            return ['source_id' => null, 'messages' => []];
        }

        $messages = Message::with(['user', 'replyTo.user'])
            ->visibleTo($user)
            ->where('source_id', $featured['source_id'])
            ->orderByDesc('id')
            ->limit((int) config('chat.history.excerpt', 8))
            ->get()
            ->reverse();

        return [
            'source_id' => $featured['source_id'],
            'messages' => app(MessagePresenter::class)->presentMany($messages),
        ];
    }

    /**
     * Shape a recording for the browse grid.
     */
    private function mapRecording(Recording $recording): array
    {
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
    }

    /**
     * Mint a playback token for this page render.
     *
     * Issued alongside the existing streamkey and not yet consumed by the
     * player; the edges do not enforce it until njs verification lands. Callers
     * must already have checked canBeAccessedBy(), because the source binding on
     * the token is what carries that decision to the edge.
     *
     * The edge claim reads the user's current assignment without triggering one,
     * so this stays free of side effects. It is replaced by a capacity-weighted
     * pick once server assignment comes out of the users table.
     *
     * See docs/streaming-auth-redesign.md.
     */
    private function playbackProps(?User $user, Show $show): ?array
    {
        if (! $show->source) {
            return null;
        }

        // A guest only gets here when login is optional, and only for a show
        // canBeAccessedBy() already cleared, which for them means an
        // unrestricted one.
        if (! $user && config('auth.required')) {
            return null;
        }

        $tokens = app(PlaybackTokenService::class);

        // No secret configured yet means this whole path is inert, which is the
        // expected state until the edges are ready to verify.
        if (! $tokens->isConfigured()) {
            return null;
        }

        return [
            'token' => $user
                ? $tokens->issueViewer(
                    user: $user,
                    source: $show->source,
                    edge: $user->server?->hostname,
                )
                : $tokens->issueGuest(source: $show->source),
            'expires_in' => $tokens->ttl(),
            'refresh_after' => $tokens->refreshAfter(),
        ];
    }

    public function external(Request $request, Show $show)
    {
        // Load show with source relationship
        $show->load('source');

        /** @var User|null $user */
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
                'description_html' => $show->description_html,
                'source' => $show->source ? $show->source->name : null,
                'status' => $show->status,
                'can_watch' => $show->canWatch(),
                'hls_url' => $hlsUrl,
            ],
            'playback' => $this->playbackProps($user, $show),
        ]);
    }

    public function show(Request $request, Show $show)
    {
        /** @var User|null $user */
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
                    // The same tile as the browse grid renders these, so without them
                    // every "other live show" below the player is a blank placeholder.
                    'thumbnail_url' => $s->thumbnail_url,
                    'viewer_count' => $s->viewer_count,
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
                'description_html' => $show->description_html,
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
            // Somewhere to go when this show is not watchable. See resolvePromotedShow().
            // Live shows skip this entirely: the player is working, so don't run promotion queries.
            'promoted' => $show->status === 'live' ? null : $this->resolvePromotedShow($user, $show),
            'initialHlsUrl' => $hlsUrl,
            'playback' => $this->playbackProps($user, $show),
            'initialStatus' => $show->isLive() ? 'online' : \Cache::get('stream.status', static fn () => StreamStatusEnum::OFFLINE->value),
            'initialListeners' => $show->viewer_count ?? StreamInfoService::getUserCount(),
            'initialOtherDevice' => false, // This feature has been removed with Client model
            'sourceId' => $show->source_id,
            ...$this->chatProps($show->source_id, $user),
        ]);
    }

    /**
     * Pop-out chat window
     */
    public function chat(Request $request, Show $show)
    {
        /** @var User|null $user */
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
            ...$this->chatProps($show->source_id, $user),
        ]);
    }
}
