<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Jobs\Telegram\SyncTelegramMessagesJob;
use App\Models\FeedbackReport;
use App\Models\TelegramMessage;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * What viewers sent in, and what has been done about it.
 *
 * The list is a triage queue, so it defaults to everything that is not resolved and
 * sorts newest first. The row itself is short on purpose: the message is usually a
 * sentence, and the diagnostics that make it actionable are on the report page.
 */
class FeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FeedbackReport::class);

        $table = Table::make(FeedbackReport::query()->with(['user', 'show', 'source', 'handler']))
            ->name('feedback')
            ->columns([
                Column::badge('type', 'Kind'),
                Column::badge('status', 'Status'),
                Column::text('message', 'Message')->searchable(),
                Column::text('reporter', 'From')->searchable('telegram'),
                Column::text('telegram', 'Telegram')->toggleable(hiddenByDefault: true),
                Column::text('show', 'Watching'),
                Column::text('browser', 'Browser'),
                Column::text('connection', 'Connection')->toggleable(hiddenByDefault: true),
                Column::datetime('created_at', 'Received')->sortable(),
                Column::text('handled', 'Handled By')->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::select('status', 'Status')
                    ->options([
                        'unresolved' => 'Needs attention',
                        FeedbackReport::STATUS_NEW => 'New',
                        FeedbackReport::STATUS_OPEN => 'Open',
                        FeedbackReport::STATUS_RESOLVED => 'Resolved',
                    ])
                    ->default('unresolved')
                    ->placeholder('Any status')
                    ->apply(fn ($query, $value) => $value === 'unresolved'
                        ? $query->unresolved()
                        : $query->where('status', $value)),
                Filter::select('type', 'Kind')
                    ->options([
                        FeedbackReport::TYPE_ISSUE => 'Stream issues',
                        FeedbackReport::TYPE_FEEDBACK => 'Feedback',
                    ])
                    ->placeholder('Any kind'),
            ])
            ->defaultSort('created_at', 'desc')
            ->rows(fn (FeedbackReport $report) => $this->row($report))
            ->recordUrl(fn (FeedbackReport $report) => route('manage.feedback.show', $report))
            ->rowActions(fn (FeedbackReport $report) => $this->rowActions($report))
            ->bulkActions($this->bulkActions());

        return inertia('Manage/Feedback/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function show(FeedbackReport $feedback): Response
    {
        $this->authorize('view', $feedback);

        return inertia('Manage/Feedback/Show', [
            'report' => [
                'id' => $feedback->id,
                'type' => Status::feedbackType($feedback->type),
                'status' => Status::feedback($feedback->status),
                'status_value' => $feedback->status,
                'message' => $feedback->message,
                'reporter' => $feedback->reporterName(),
                'account' => $feedback->user?->name,
                'telegram' => $feedback->telegramHandle(),
                'telegram_url' => $feedback->telegramUrl(),
                'show' => $feedback->show?->title,
                'show_url' => $feedback->show ? route('manage.shows.edit', $feedback->show) : null,
                'source' => $feedback->source?->name,
                'url' => $feedback->url,
                'ip' => $feedback->ip,
                'user_agent' => $feedback->user_agent,
                'received' => $feedback->created_at?->diffForHumans(),
                'received_exact' => $feedback->created_at?->toDayDateTimeString(),
                // A report closed from a Telegram chat has no account behind it, only
                // the handle of whoever pressed the button.
                'handled_by' => $feedback->handler?->name ?? $feedback->handled_note,
                'handled_at' => $feedback->handled_at?->diffForHumans(),
                'diagnostics' => $feedback->diagnosticGroups(),
                'index_url' => route('manage.feedback.index'),
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->rowActions($feedback, detail: true),
            ),
        ]);
    }

    /**
     * Move a report along the queue. Both directions: a report marked resolved in
     * haste has to be able to come back.
     */
    public function updateStatus(Request $request, FeedbackReport $feedback): RedirectResponse
    {
        $this->authorize('update', $feedback);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                FeedbackReport::STATUS_NEW,
                FeedbackReport::STATUS_OPEN,
                FeedbackReport::STATUS_RESOLVED,
            ])],
        ]);

        $this->applyStatus($feedback, $validated['status'], $request);

        Toast::flashSuccess('Report updated', 'Marked '.$validated['status'].'.');

        return back();
    }

    public function bulkResolve(Request $request): RedirectResponse
    {
        $this->authorize('manage', FeedbackReport::class);

        $reports = $this->selection($request);

        $reports->each(fn (FeedbackReport $report) => $this->applyStatus(
            $report,
            FeedbackReport::STATUS_RESOLVED,
            $request,
        ));

        Toast::flashSuccess('Reports resolved', $reports->count().' report(s) closed.');

        return back();
    }

    public function destroy(FeedbackReport $feedback): RedirectResponse
    {
        $this->authorize('delete', $feedback);

        $feedback->delete();

        Toast::flashSuccess('Report deleted', 'It is gone, along with its diagnostics.');

        return to_route('manage.feedback.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('manage', FeedbackReport::class);

        $count = $this->selection($request)->each(fn (FeedbackReport $report) => $report->delete())->count();

        Toast::flashSuccess('Reports deleted', $count.' report(s) removed.');

        return back();
    }

    /**
     * @return Collection<int, FeedbackReport>
     */
    private function selection(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        return FeedbackReport::whereIn('id', $validated['ids'])->get();
    }

    private function applyStatus(FeedbackReport $report, string $status, Request $request): void
    {
        $report->forceFill([
            'status' => $status,
            // Who last touched it, not who resolved it: reopening is a decision worth
            // attributing too.
            'handled_by' => $request->user()?->id,
            'handled_note' => null,
            'handled_at' => now(),
        ])->save();

        // Whatever the bot posted about this report says what the panel now says.
        SyncTelegramMessagesJob::dispatch(TelegramMessage::KIND_FEEDBACK, $report->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(FeedbackReport $report): array
    {
        $diagnostics = $report->diagnostics ?? [];
        $browser = $diagnostics['browser'] ?? [];
        $connection = $diagnostics['connection'] ?? [];

        return [
            'id' => $report->id,
            'type' => Status::feedbackType($report->type),
            'status' => Status::feedback($report->status),
            'message' => $report->excerpt(),
            'reporter' => $report->reporterName(),
            'telegram' => $report->telegramHandle() ?? '-',
            'show' => $report->show?->title ?? ($report->source?->name ?? '-'),
            'browser' => $this->summary($browser, ['name', 'version', 'os']),
            'connection' => $this->summary($connection, ['effectiveType', 'downlink']),
            'created_at' => $report->created_at,
            'handled' => $report->handler?->name ?? $report->handled_note ?? '-',
        ];
    }

    /**
     * A few keys of a diagnostics group collapsed to one cell, skipping whatever the
     * browser did not report.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     */
    private function summary(mixed $values, array $keys): string
    {
        if (! is_array($values)) {
            return '-';
        }

        $parts = array_filter(array_map(
            fn (string $key) => is_scalar($values[$key] ?? null) ? (string) $values[$key] : null,
            $keys,
        ));

        return $parts === [] ? '-' : implode(' ', $parts);
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(FeedbackReport $report, bool $detail = false): array
    {
        $actions = [];

        if (! $detail) {
            $actions[] = Action::link('view', 'Open', route('manage.feedback.show', $report))->icon('eye');
        }

        if (! request()->user()->can('update', $report)) {
            return $actions;
        }

        if ($report->status !== FeedbackReport::STATUS_RESOLVED) {
            if ($report->status === FeedbackReport::STATUS_NEW) {
                $actions[] = Action::post('open', 'Mark as open', $this->statusUrl($report, FeedbackReport::STATUS_OPEN))
                    ->icon('clock');
            }

            $actions[] = Action::post('resolve', 'Mark as resolved', $this->statusUrl($report, FeedbackReport::STATUS_RESOLVED))
                ->icon('circle-check')
                ->tone(Status::OK);
        } else {
            $actions[] = Action::post('reopen', 'Reopen', $this->statusUrl($report, FeedbackReport::STATUS_OPEN))
                ->icon('refresh-cw');
        }

        if (request()->user()->can('delete', $report)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.feedback.destroy', $report))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Delete report',
                    'The message and the diagnostics sent with it are removed.',
                    'Delete',
                );
        }

        return $actions;
    }

    /**
     * The target state travels in the query string, so a one-click action needs no
     * modal to collect it. `$request->validate()` reads query and body alike.
     */
    private function statusUrl(FeedbackReport $report, string $status): string
    {
        return route('manage.feedback.status', ['feedback' => $report, 'status' => $status]);
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        $user = request()->user();
        $actions = [];

        if ($user->can('manage', FeedbackReport::class)) {
            $actions[] = Action::post('resolve', 'Mark resolved', route('manage.feedback.bulk.resolve'))
                ->icon('circle-check')
                ->tone(Status::OK);
        }

        if ($user->can('manage', FeedbackReport::class)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.feedback.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm('Delete reports', 'The selected reports and their diagnostics are removed.', 'Delete');
        }

        return $actions;
    }
}
