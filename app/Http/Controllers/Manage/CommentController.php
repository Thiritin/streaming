<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Jobs\Telegram\SyncTelegramMessagesJob;
use App\Models\ChatBan;
use App\Models\RecordingComment;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramNotifier;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * Everything viewers have said under a recording, in one list.
 *
 * The watch page is where a single comment is dealt with; this is for the sweep -
 * a run of spam under one recording, or one account that has been busy across
 * several. Newest first, because what has just been posted is what nobody has
 * read yet.
 */
class CommentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RecordingComment::class);

        $table = Table::make(
            RecordingComment::query()
                ->withCount(['hearts', 'reports as open_reports_count' => fn ($query) => $query->unresolved()])
                ->with(['user', 'recording', 'parent.user', 'reports' => fn ($query) => $query->unresolved()->with('user:id,name')->latest()])
        )
            ->name('comments')
            ->columns([
                Column::text('body', 'Comment')->searchable(),
                Column::badge('kind', 'Kind'),
                Column::badge('state', 'State'),
                Column::text('reported_for', 'Reported for')->toggleable(hiddenByDefault: true),
                Column::text('author', 'From')->searchable('user.name'),
                Column::text('recording', 'Under')->searchable('recording.title'),
                Column::number('hearts', 'Hearts')->sortable('hearts_count'),
                Column::datetime('created_at', 'Posted')->sortable(),
            ])
            ->filters([
                /*
                 * Reported leads it: a comment a report has taken down is the only
                 * row on this page anybody is waiting on.
                 */
                Filter::select('state', 'State')
                    ->options([
                        'reported' => 'Reported, still hidden',
                        'approved' => 'Approved',
                        'visible' => 'Up',
                    ])
                    ->placeholder('Any state')
                    ->apply(fn ($query, $value) => match ($value) {
                        'reported' => $query->whereNotNull('hidden_at'),
                        'approved' => $query->whereNotNull('approved_at'),
                        default => $query->whereNull('hidden_at'),
                    }),
                Filter::select('kind', 'Kind')
                    ->options(['comment' => 'Comments', 'reply' => 'Replies'])
                    ->placeholder('Comments and replies')
                    ->apply(fn ($query, $value) => $value === 'reply'
                        ? $query->whereNotNull('parent_id')
                        : $query->whereNull('parent_id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->rows(fn (RecordingComment $comment) => $this->row($comment))
            ->recordUrl(fn (RecordingComment $comment) => route('manage.comments.show', $comment))
            ->rowActions(fn (RecordingComment $comment) => $this->rowActions($comment))
            ->bulkActions($this->bulkActions());

        return inertia('Manage/Comments/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function destroy(RecordingComment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $replies = $comment->replies()->count();
        $author = $comment->user?->name ?? 'a deleted account';
        $id = $comment->id;

        $comment->delete();

        app(TelegramNotifier::class)->commentDeleted($id, $author, request()->user()->name);

        Toast::flashSuccess(
            'Comment deleted',
            $replies > 0 ? "It is gone, with {$replies} repl".($replies === 1 ? 'y' : 'ies').'.' : 'It is gone.',
        );

        // The row it was deleted from may have been its own page.
        return str_contains(url()->previous(), (string) $comment->id)
            ? to_route('manage.comments.index')
            : back();
    }

    /**
     * One comment, with what was said about it and what can be done to it.
     *
     * The page is where a report is worked: the thread it sits in, the reports
     * against it, and the three decisions - put it back, take it down, stop the
     * account - side by side rather than spread across a table row's menu.
     */
    public function show(RecordingComment $comment): Response
    {
        $this->authorize('viewAny', RecordingComment::class);

        $comment->load([
            'user',
            'recording',
            'parent.user',
            'replies.user',
            'approver',
            'reports' => fn ($query) => $query->with('user:id,name')->latest(),
        ]);

        return inertia('Manage/Comments/Show', [
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user?->name ?? 'Deleted account',
                'author_id' => $comment->user_id,
                'posted' => $comment->created_at?->diffForHumans(),
                'posted_exact' => $comment->created_at?->toDayDateTimeString(),
                'edited' => $comment->edited_at?->diffForHumans(),
                'hearts' => $comment->hearts()->count(),
                'state' => match (true) {
                    $comment->isHidden() => Status::make('Reported', Status::DANGER),
                    $comment->approved_at !== null => Status::make('Approved', Status::OK),
                    default => Status::make('Up', Status::IDLE),
                },
                'hidden_since' => $comment->hidden_at?->diffForHumans(),
                'approved_by' => $comment->approver?->name,
                'approved_at' => $comment->approved_at?->toDayDateTimeString(),
                'recording' => $comment->recording?->title,
                'recording_url' => $comment->recording
                    ? route('recordings.show', $comment->recording)
                    : null,
                'recording_edit_url' => $comment->recording
                    ? route('manage.recordings.edit', $comment->recording)
                    : null,
                // What it is answering, and what is answering it: a line read on its
                // own is the most common way to misread one.
                'parent' => $comment->parent ? [
                    'id' => $comment->parent->id,
                    'body' => $comment->parent->excerpt(300),
                    'author' => $comment->parent->user?->name ?? 'Deleted account',
                    'url' => route('manage.comments.show', $comment->parent),
                ] : null,
                'replies' => $comment->replies->map(fn (RecordingComment $reply) => [
                    'id' => $reply->id,
                    'body' => $reply->excerpt(300),
                    'author' => $reply->user?->name ?? 'Deleted account',
                    'url' => route('manage.comments.show', $reply),
                ])->values()->all(),
                'reports' => $comment->reports->map(fn ($report) => [
                    'id' => $report->id,
                    'message' => $report->message,
                    'by' => $report->user?->name ?? 'Deleted account',
                    'at' => $report->created_at?->diffForHumans(),
                    'resolved' => $report->resolved_at !== null,
                ])->values()->all(),
                'index_url' => route('manage.comments.index'),
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->rowActions($comment),
            ),
        ]);
    }

    public function approve(RecordingComment $comment): RedirectResponse
    {
        $this->authorize('manage', RecordingComment::class);

        $comment->approve(request()->user());

        SyncTelegramMessagesJob::dispatch(TelegramMessage::KIND_COMMENT, $comment->id);

        Toast::flashSuccess('Comment approved', 'It is back up, and further reports will not hide it.');

        return back();
    }

    /**
     * Stop an account posting, from the report that made the case for it.
     *
     * A chat ban rather than a comment-only one: the comment box already refuses
     * anyone chat has silenced, so a second kind of ban would be a second thing to
     * remember to lift.
     */
    public function ban(Request $request, RecordingComment $comment): RedirectResponse
    {
        $this->authorize('manage', RecordingComment::class);

        abort_unless($request->user()->canBanFromChat(), 403);
        abort_unless($comment->user !== null, 404, 'That comment has no account behind it.');

        $validated = $request->validate([
            'duration' => ['required', Rule::in(['24h', '7d', '30d', 'forever'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $expires = match ($validated['duration']) {
            '24h' => now()->addDay(),
            '7d' => now()->addWeek(),
            '30d' => now()->addDays(30),
            default => null,
        };

        ChatBan::create([
            'user_id' => $comment->user_id,
            'banned_by_user_id' => $request->user()->id,
            'reason' => $validated['reason'] ?: 'Comment moderation',
            'expires_at' => $expires,
        ]);

        Toast::flashSuccess(
            $comment->user->name.' banned',
            $expires ? 'Until '.$expires->toDayDateTimeString().'.' : 'Permanently, until it is lifted.',
        );

        return back();
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $this->authorize('manage', RecordingComment::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $comments = RecordingComment::whereIn('id', $validated['ids'])->get();
        $comments->each(function (RecordingComment $comment) use ($request) {
            $comment->approve($request->user());
            SyncTelegramMessagesJob::dispatch(TelegramMessage::KIND_COMMENT, $comment->id);
        });

        Toast::flashSuccess('Comments approved', $comments->count().' comment(s) back up.');

        return back();
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('manage', RecordingComment::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        /*
         * Counted before the delete rather than from what came back: a selection
         * that holds both a comment and one of its replies loses the reply to the
         * parent's cascade, and a row already gone is not an error worth raising.
         */
        $count = RecordingComment::whereIn('id', $validated['ids'])->count();

        RecordingComment::whereIn('id', $validated['ids'])->get()->each->delete();

        Toast::flashSuccess('Comments deleted', $count.' comment(s) removed, with any replies under them.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RecordingComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->excerpt(),
            'kind' => $comment->isReply()
                ? Status::make('Reply', Status::IDLE)
                : Status::make('Comment', Status::INFO),
            'author' => $comment->user?->name ?? 'Deleted account',
            'recording' => $comment->recording?->title ?? '-',
            'hearts' => (int) $comment->hearts_count,
            'state' => match (true) {
                $comment->isHidden() => Status::make('Reported', Status::DANGER),
                $comment->approved_at !== null => Status::make('Approved', Status::OK),
                default => Status::make('Up', Status::IDLE),
            },
            'reported_for' => $comment->reports->pluck('message')->implode(' | ') ?: '-',
            'created_at' => $comment->created_at,
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(RecordingComment $comment): array
    {
        $actions = [];

        if ($comment->recording) {
            $actions[] = Action::link(
                'watch',
                'Open recording',
                route('recordings.show', $comment->recording),
            )->icon('external-link')->newTab();
        }

        if ($comment->isHidden()) {
            $actions[] = Action::post('approve', 'Approve', route('manage.comments.approve', $comment))
                ->icon('circle-check')
                ->tone(Status::OK)
                ->confirm(
                    'Put this comment back?',
                    'It goes back up for everyone and cannot be hidden by another report.',
                    'Approve',
                );
        }

        /*
         * Banning is here rather than only in Users because the report is where the
         * decision is made: whoever is reading what an account posted is the one who
         * knows it should stop. It writes the same chat ban the chat commands do -
         * one silence covers both rooms, which is what the comment box has always
         * honoured.
         */
        if ($comment->user && request()->user()?->canBanFromChat()) {
            $actions[] = Action::post('ban', 'Ban author', route('manage.comments.ban', $comment))
                ->icon('circle-slash')
                ->tone(Status::DANGER)
                ->confirm(
                    'Ban '.$comment->user->name.'?',
                    'They stop being able to comment or write in chat. What they have already posted stays up until it is deleted.',
                    'Ban',
                )
                ->fields([
                    [
                        'key' => 'duration',
                        'label' => 'For how long',
                        'type' => 'select',
                        'required' => true,
                        'default' => '7d',
                        'options' => [
                            ['value' => '24h', 'label' => '24 hours'],
                            ['value' => '7d', 'label' => '7 days'],
                            ['value' => '30d', 'label' => '30 days'],
                            ['value' => 'forever', 'label' => 'Permanently'],
                        ],
                    ],
                    [
                        'key' => 'reason',
                        'label' => 'Reason',
                        'type' => 'text',
                        'required' => false,
                        'helper' => 'Kept with the ban, for whoever reads it next.',
                    ],
                ]);
        }

        $actions[] = Action::delete('delete', 'Delete', route('manage.comments.destroy', $comment))
            ->icon('trash-2')
            ->tone(Status::DANGER)
            ->confirm(
                'Delete this comment?',
                'Any replies under it go too. Nobody is notified.',
                'Delete',
            );

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        return [
            Action::post('approve', 'Approve', route('manage.comments.bulk.approve'))
                ->icon('circle-check')
                ->tone(Status::OK)
                ->confirm(
                    'Put the selected comments back?',
                    'Each goes back up and stops being hidden by further reports.',
                    'Approve',
                ),
            Action::delete('delete', 'Delete', route('manage.comments.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Delete the selected comments?',
                    'Replies under any of them go too.',
                    'Delete',
                ),
        ];
    }
}
