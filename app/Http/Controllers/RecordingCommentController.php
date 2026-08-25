<?php

namespace App\Http\Controllers;

use App\Jobs\Telegram\SendCommentReportAlertJob;
use App\Jobs\Telegram\SyncTelegramMessagesJob;
use App\Models\Recording;
use App\Models\RecordingComment;
use App\Models\RecordingCommentReport;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramNotifier;
use App\Support\CommentText;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Posting and unposting under a recording.
 *
 * Both answer a redirect back rather than JSON: the thread arrives with the page
 * as an Inertia prop, so `back()` is what puts the new comment on screen. There
 * is no fetch() anywhere near this.
 */
class RecordingCommentController extends Controller
{
    /**
     * Root comments per page. Load more asks for another page's worth on top of
     * what is already on screen rather than for a page of its own, so posting or
     * hearting re-renders everything the viewer had opened.
     */
    public const PAGE_SIZE = 20;

    /**
     * The ceiling that window grows to. A thread this long is not read to the end;
     * it stops the URL being a way to ask the server for the whole table.
     */
    public const MAX_SHOWN = 500;

    /**
     * Seconds between one viewer's comments, and how many they get in an hour.
     *
     * The route throttle is about a script hammering the endpoint; this is about a
     * person. A thread is unreadable long before somebody has written thirty
     * comments under one recording, and a pause between them is what stops an
     * argument being conducted six words at a time.
     */
    public const COOLDOWN_SECONDS = 10;

    public const HOURLY_LIMIT = 30;

    public function store(Request $request, Recording $recording): RedirectResponse
    {
        $user = Auth::user();

        // The same 404 the archive answers for a recording nobody may see, so the
        // comment box cannot be used to find out that one exists.
        if (! $recording->is_published || ! $recording->canBeAccessedBy($user)) {
            abort(404);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.CommentText::MAX_LENGTH],
            'parent_id' => ['nullable', 'integer'],
        ]);

        /*
         * A chat ban or a timeout is about this audience, not about that one box.
         * Somebody silenced in chat during a show should not be able to carry on
         * under the recording of it an hour later.
         */
        $silenced = $this->silencedUntil($user);

        // Compared against null rather than tested for truth: a permanent ban has
        // nothing to say about when it ends, and an empty string is falsy.
        if ($silenced !== null) {
            return back()->withErrors([
                'body' => 'You are not able to post right now'.$silenced.'.',
            ]);
        }

        $body = CommentText::normalise($data['body']);

        if ($body === '') {
            return back()->withErrors(['body' => 'Write something first.']);
        }

        if ($wait = $this->cooldownLeft($user)) {
            return back()->withErrors([
                'body' => "Hold on {$wait} more second".($wait === 1 ? '' : 's').'.',
            ]);
        }

        if (RateLimiter::tooManyAttempts($this->hourlyKey($user), self::HOURLY_LIMIT)) {
            $minutes = (int) ceil(RateLimiter::availableIn($this->hourlyKey($user)) / 60);

            return back()->withErrors([
                'body' => 'That is a lot of comments for one hour. Try again in '.max(1, $minutes).' min.',
            ]);
        }

        RateLimiter::hit($this->hourlyKey($user), 3600);

        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $user->id,
            'parent_id' => $this->parentFor($recording, $data['parent_id'] ?? null),
            'body' => $body,
        ]);

        return back();
    }

    /**
     * Rewrite a comment. Its author only, and for as long as it is there: a
     * typo found an hour later is the ordinary case, and a window that closes
     * would only mean the same correction posted again underneath.
     */
    public function update(Request $request, Recording $recording, RecordingComment $comment): RedirectResponse
    {
        abort_if($comment->recording_id !== $recording->id, 404);

        $this->authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.CommentText::MAX_LENGTH],
        ]);

        $body = CommentText::normalise($data['body']);

        if ($body === '') {
            return back()->withErrors(['body' => 'A comment cannot be emptied. Delete it instead.']);
        }

        $comment->editBody($body);

        return back();
    }

    public function destroy(Recording $recording, RecordingComment $comment): RedirectResponse
    {
        abort_if($comment->recording_id !== $recording->id, 404);

        $this->authorize('delete', $comment);

        $author = $comment->user?->name ?? 'a deleted account';
        $id = $comment->id;

        // Replies go with it; see the create migration.
        $comment->delete();

        app(TelegramNotifier::class)->commentDeleted($id, $author, Auth::user()->name);

        return back();
    }

    /**
     * Heart a comment, or take the heart back.
     *
     * One press either way rather than two endpoints: the button is a toggle, and
     * a client that has fallen out of step with the row would otherwise have to
     * guess which of the two to call.
     */
    public function heart(Recording $recording, RecordingComment $comment): RedirectResponse
    {
        abort_if($comment->recording_id !== $recording->id, 404);

        $user = Auth::user();

        abort_if($user->isSilenced(), 403, 'You are not able to do that right now.');

        if ($comment->hearts()->where('user_id', $user->id)->exists()) {
            $comment->hearts()->detach($user->id);
        } else {
            // Detach-free and idempotent: two presses racing each other cannot
            // insert two rows past the unique index.
            $comment->hearts()->syncWithoutDetaching([$user->id]);
        }

        return back();
    }

    /**
     * Report a comment to moderation, which takes it down on the spot.
     *
     * Hidden first and read later, because the whole point of the button is the
     * hour between somebody seeing a thing and a moderator being awake. It is not
     * a deletion: the comment is still there, its author still sees it, and a
     * moderator either puts it back or removes it for good.
     */
    public function report(Request $request, Recording $recording, RecordingComment $comment): RedirectResponse
    {
        abort_if($comment->recording_id !== $recording->id, 404);

        $user = Auth::user();

        abort_if($user->isSilenced(), 403, 'You are not able to do that right now.');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $message = CommentText::normalise($data['message']);

        if ($message === '') {
            return back()->withErrors(['message' => 'Say what is wrong with it.']);
        }

        // Its author reporting it is not a report; it is a delete they have not
        // found yet.
        if ($comment->user_id === $user->id) {
            return back()->withErrors(['message' => 'This is your own comment. Delete it instead.']);
        }

        RecordingCommentReport::updateOrCreate(
            ['recording_comment_id' => $comment->id, 'user_id' => $user->id],
            ['message' => mb_substr($message, 0, 500), 'resolved_at' => null],
        );

        $comment->hideOnReport();

        // Only once it has actually gone dark: an approved comment being reported
        // again changes nothing, and a chat told about it twice is noise.
        if ($comment->isHidden()) {
            SendCommentReportAlertJob::dispatch($comment->id);
        }

        return back();
    }

    /**
     * Put a reported comment back, from the page it was reported on.
     *
     * Here as well as in /manage because the fastest fix for a comment reported
     * out of spite is the moderator who is already reading the thread.
     */
    public function approve(Recording $recording, RecordingComment $comment): RedirectResponse
    {
        abort_if($comment->recording_id !== $recording->id, 404);

        $this->authorize('manage', RecordingComment::class);

        $comment->approve(Auth::user());

        // Whatever the bot posted about it now says what the panel says.
        SyncTelegramMessagesJob::dispatch(TelegramMessage::KIND_COMMENT, $comment->id);

        return back();
    }

    /**
     * Seconds this viewer still has to wait, or 0.
     *
     * Measured against their last comment rather than counted in the limiter,
     * because the answer has to be a number of seconds to say out loud, and a
     * comment they wrote before the cache was cleared still counts.
     */
    private function cooldownLeft($user): int
    {
        $last = RecordingComment::where('user_id', $user->id)->latest('created_at')->value('created_at');

        if (! $last) {
            return 0;
        }

        return max(0, self::COOLDOWN_SECONDS - (int) $last->diffInSeconds(now()));
    }

    private function hourlyKey($user): string
    {
        return 'recording-comments:'.$user->id;
    }

    /**
     * The comment a reply hangs off, or null for a new thread.
     *
     * A reply to a reply is filed under the same parent rather than deepening the
     * thread: the section is one level by design, and a client that posts against
     * a reply is answered by putting its comment where a reader will find it
     * instead of by an error nobody can act on.
     */
    private function parentFor(Recording $recording, ?int $parentId): ?int
    {
        if (! $parentId) {
            return null;
        }

        $parent = RecordingComment::where('recording_id', $recording->id)->find($parentId);

        if (! $parent) {
            return null;
        }

        return $parent->parent_id ?? $parent->id;
    }

    /**
     * Why this viewer cannot post, phrased to be appended to a sentence, or null.
     */
    private function silencedUntil($user): ?string
    {
        if ($ban = $user->activeChatBan()) {
            return $ban->isPermanent() ? '' : ' until '.$ban->expires_at->diffForHumans();
        }

        if ($timeout = $user->activeTimeout()) {
            return ' until '.$timeout->expires_at->diffForHumans();
        }

        return null;
    }

    /**
     * The thread under a recording, shaped for the page.
     *
     * Most hearted first, and newest first between comments nobody has hearted:
     * a room agrees about what was worth saying faster than it stops saying
     * things, and the alternative buries every new comment under the year-old one
     * with four hearts on it. Replies keep the order they were written in, because
     * a reply chain read out of sequence is nonsense.
     *
     * Paged by root comment. A reply never counts towards the page: a comment
     * arriving with nine replies would otherwise fill a page on its own.
     *
     * @return array{comments: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function thread(Recording $recording, $user, int $limit = self::PAGE_SIZE): array
    {
        if (! self::availableTo($user)) {
            return ['comments' => [], 'meta' => ['shown' => 0, 'total' => 0, 'hasMore' => false]];
        }

        $limit = max(self::PAGE_SIZE, min($limit, self::MAX_SHOWN));

        $roots = self::withViewerState($recording->comments()->roots()->visibleTo($user), $user)
            ->orderByDesc('hearts_count')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $replies = $roots->isEmpty()
            ? collect()
            : self::withViewerState(
                $recording->comments()->whereIn('parent_id', $roots->modelKeys())->visibleTo($user),
                $user,
            )->orderBy('created_at')->get()->groupBy('parent_id');

        $total = $recording->comments()->roots()->visibleTo($user)->count();

        return [
            'comments' => $roots
                ->map(fn (RecordingComment $comment) => self::shape($comment, $user) + [
                    'replies' => $replies->get($comment->id, collect())
                        ->map(fn (RecordingComment $reply) => self::shape($reply, $user))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'meta' => [
                'shown' => $roots->count(),
                'total' => $total,
                'hasMore' => $total > $roots->count(),
                'pageSize' => self::PAGE_SIZE,
            ],
        ];
    }

    /**
     * Whether this viewer gets a comment section at all: the installation's switch,
     * their own, and whether they are silenced. A banned account is shown nothing
     * rather than a section it can only read - the section is a conversation, and
     * being handed the transcript of one you have been thrown out of is worse than
     * being handed nothing.
     */
    public static function availableTo($user): bool
    {
        return Features::enabledFor('comments', $user) && ! ($user?->isSilenced() ?? false);
    }

    /**
     * How many root comments the page asked for. Clamped in thread(); a hand-typed
     * number cannot ask for the whole table.
     */
    public static function limitFrom(Request $request): int
    {
        return (int) $request->integer('comments', self::PAGE_SIZE);
    }

    /**
     * The counts and flags a comment is rendered with: how many hearts it has, and
     * whether this viewer is one of them.
     */
    private static function withViewerState($query, $user)
    {
        return $query
            ->with('user:id,name,avatar')
            ->withCount('hearts')
            ->when(
                $user?->can('manage', RecordingComment::class),
                fn ($query) => $query
                    ->withCount(['reports' => fn ($inner) => $inner->unresolved()])
                    ->with(['reports' => fn ($inner) => $inner->unresolved()->with('user:id,name')->latest()->limit(3)]),
            )
            ->when($user, fn ($query) => $query->withExists([
                'hearts as hearted' => fn ($inner) => $inner->where('users.id', $user->id),
            ]));
    }

    private static function moderates($user): bool
    {
        return $user?->can('manage', RecordingComment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape(RecordingComment $comment, $user): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toJSON(),
            'hearts' => (int) ($comment->hearts_count ?? 0),
            'hearted' => (bool) ($comment->hearted ?? false),
            'author' => [
                'id' => $comment->user_id,
                'name' => $comment->user?->name ?? 'Deleted account',
                'avatar' => $comment->user?->avatar,
            ],
            // Answered per comment rather than as one "can moderate" flag, because
            // an author may delete their own and nobody else's.
            'can_delete' => $user?->can('delete', $comment) ?? false,
            'can_edit' => $user?->can('update', $comment) ?? false,
            'edited' => $comment->edited_at !== null,
            // Somebody else's, once, and only while it is still up.
            'can_report' => $user !== null && $comment->user_id !== $user->id && ! $comment->isHidden(),
            'hidden' => $comment->isHidden(),
            /*
             * Why it is on screen at all while hidden: its author is told it is
             * being looked at, a moderator is told what was said about it. Nobody
             * else is given the row to begin with.
             */
            'hidden_for' => $comment->isHidden()
                ? (self::moderates($user) ? 'moderator' : 'author')
                : null,
            'reports' => self::moderates($user)
                ? $comment->reports->map(fn (RecordingCommentReport $report) => [
                    'id' => $report->id,
                    'message' => $report->message,
                    'by' => $report->user?->name ?? 'Deleted account',
                ])->values()->all()
                : [],
            'report_count' => (int) ($comment->reports_count ?? 0),
            'can_approve' => self::moderates($user) && $comment->isHidden(),
        ];
    }
}
