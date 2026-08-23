<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Support\EventFilter;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * The recording plan: one row per show, one column per thing that has to be decided or
 * found out about its recording.
 *
 * Separate from the Shows table on purpose. That one answers "what is on air and what is
 * next", is sorted for the running order and hides ended shows by default - all of which
 * is wrong for this job, where the interesting rows are precisely the ones that have
 * finished and produced nothing. This page keeps every row on screen at once, edits every
 * cell in place with no mode to switch on, and is read down a column rather than across a
 * row: who has what, and what nobody has.
 *
 * It is meant to be worked as a board by several people at once, which is why rows can be
 * grouped by whoever is responsible rather than by day, why there is a one-click way to
 * put your own name on a row, and why every filter lives in the query string - a view of
 * the work is a link you can hand someone.
 *
 * Nothing here gates anything. See the migration that added `shows.publish_plan`.
 */
class RecordingPlanController extends Controller
{
    /**
     * How many rows the grid will render before it starts asking for a narrower filter.
     * Not a page: a plan that is paginated cannot be read down a column, which is the
     * whole point. A run of a few hundred slots is comfortably inside this.
     */
    private const ROW_LIMIT = 600;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Show::class);

        $filters = $this->filters($request);

        $query = Show::query()
            ->with(['source', 'recordingOwner', 'recordings'])
            ->orderBy('scheduled_start')
            ->orderBy('id');

        if (! $filters['show_archived']) {
            $query->notArchived();
        }

        $this->applyFilters($query, $filters, $request->user());

        $shows = $query->limit(self::ROW_LIMIT + 1)->get();
        $truncated = $shows->count() > self::ROW_LIMIT;
        $shows = $shows->take(self::ROW_LIMIT);

        $rows = $shows->map(fn (Show $show) => $this->row($show))->values();

        return inertia('Manage/Recordings/Plan', [
            // Grouping is a presentation choice, but the order it needs is not, so the
            // rows arrive already sorted the way they will be read.
            'rows' => $this->sortForGrouping($rows, $filters['group'])->all(),
            'summary' => $this->summary($shows),
            'filters' => $filters,
            'options' => [
                'sources' => $this->sourceOptions(),
                'owners' => $this->ownerOptions(),
                'plans' => $this->planOptions(),
                'streams' => $this->streamOptions(),
                'onsites' => $this->onsiteOptions(),
                'states' => $this->stateOptions(),
                'events' => $this->eventOptions(),
                'days' => $this->dayOptions($filters),
                'groups' => [
                    ['value' => 'day', 'label' => 'Group by day'],
                    ['value' => 'owner', 'label' => 'Group by person'],
                    ['value' => 'source', 'label' => 'Group by source'],
                    ['value' => 'none', 'label' => 'No grouping'],
                ],
            ],
            'defaults' => [
                // The client needs these to tell a filter that is set from one that is
                // merely at its default, which is what decides whether Clear appears.
                'event' => $this->defaultEvent(),
                'group' => 'day',
            ],
            'urls' => [
                'bulk' => route('manage.shows.recording-plan.bulk'),
                'recordings' => route('manage.recordings.index'),
            ],
            'me' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
            'can_edit' => $request->user()->can('create', Show::class),
            'truncated' => $truncated,
            'limit' => self::ROW_LIMIT,
        ]);
    }

    /**
     * One cell of one row.
     *
     * Everything is `sometimes`, as on the shows inline endpoint: the client sends the
     * field that changed and nothing else, so a missing key means "leave it" rather than
     * "clear it". An owner, a stream verdict and an onsite verdict are all clearable, so
     * all three accept null.
     */
    public function update(Request $request, Show $show): RedirectResponse
    {
        $this->authorize('update', $show);

        $validated = $request->validate($this->rules());

        $show->update(
            array_diff_key($validated, array_flip(['archive_pgm', 'archive_iso']))
            + $this->archiveChanges($validated, $show)
        );

        return back();
    }

    /**
     * The same decisions, applied to every ticked row at once.
     *
     * This is what the page is for on the day the programme lands: two hundred imported
     * slots arrive `undecided`, and marking a stage's worth of them one at a time is the
     * thing that does not get done. It is also how a whole morning is written off after a
     * card failure, and how somebody takes a day's work in one go. Rows the operator may
     * not update are skipped rather than failing the batch, and the toast says how many
     * were actually written.
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules() + [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $changes = array_intersect_key($validated, array_flip([
            'publish_plan', 'recording_owner_id', 'stream_condition', 'onsite_status',
        ])) + $this->archiveChanges($validated);

        if ($changes === []) {
            Toast::flashDanger('Nothing to apply', 'Choose something to set first.');

            return back();
        }

        $shows = Show::whereIn('id', $validated['ids'])->get();
        $allowed = $shows->filter(fn (Show $show) => $request->user()->can('update', $show));

        Show::whereIn('id', $allowed->pluck('id'))->update($changes);

        $skipped = $shows->count() - $allowed->count();

        Toast::flashSuccess(
            $allowed->count().' '.($allowed->count() === 1 ? 'show' : 'shows').' updated',
            $skipped > 0 ? $skipped.' skipped: you cannot change those.' : null,
        );

        return back();
    }

    /**
     * One rule set for both write paths, so a value the grid refuses cannot be smuggled
     * in through the bulk bar.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'publish_plan' => ['sometimes', 'required', Rule::in(Show::PUBLISH_PLANS)],
            'recording_owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'stream_condition' => ['sometimes', 'nullable', Rule::in(Show::STREAM_CONDITIONS)],
            'onsite_status' => ['sometimes', 'nullable', Rule::in(Show::ONSITE_STATUSES)],
            'recording_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Sent as booleans and stored as timestamps: the grid asks "is it up?", the
            // column answers "when did it go up".
            'archive_pgm' => ['sometimes', 'boolean'],
            'archive_iso' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Turns the two archive toggles into the timestamps they are stored as. Re-ticking an
     * already-ticked box leaves the original time alone: the useful answer is when it
     * first went up, not when someone last looked at the row.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function archiveChanges(array $validated, ?Show $show = null): array
    {
        $changes = [];

        foreach (Show::ARCHIVE_TRANSFERS as $transfer) {
            if (! array_key_exists('archive_'.$transfer, $validated)) {
                continue;
            }

            $column = 'archive_'.$transfer.'_at';
            $on = (bool) $validated['archive_'.$transfer];

            if (! $on) {
                $changes[$column] = null;

                continue;
            }

            if ($show === null || $show->{$column} === null) {
                $changes[$column] = now();
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Show $show): array
    {
        $state = $show->recordingState();
        $recording = $show->recordings->sortByDesc('id')->first();

        return [
            'id' => $show->id,
            'title' => $show->title,
            'url' => route('manage.shows.edit', $show),
            'source' => $show->source?->name,
            'source_id' => $show->source_id,
            'day' => $show->scheduled_start?->format('Y-m-d'),
            'day_label' => $show->scheduled_start?->format('l j F'),
            'start' => $show->scheduled_start?->format('H:i'),
            'end' => $show->scheduled_end?->format('H:i'),
            'status' => Status::show($show->status),
            'publish_plan' => $show->publish_plan,
            'owner_id' => $show->recording_owner_id,
            'owner' => $show->recordingOwner?->name,
            'stream_condition' => $show->stream_condition,
            'onsite_status' => $show->onsite_status,
            'archive_pgm' => $show->archive_pgm_at !== null,
            'archive_iso' => $show->archive_iso_at !== null,
            'archive_pgm_at' => $show->archive_pgm_at?->format('D j M, H:i'),
            'archive_iso_at' => $show->archive_iso_at?->format('D j M, H:i'),
            'needs_archive' => $show->needsMediaArchive(),
            'note' => $show->recording_note,
            'state' => $state,
            'state_status' => Status::recordingState($state),
            'gap' => $show->isRecordingGap(),
            // Drives the amber on the onsite cell. The whole reason the two captures are
            // separate columns: this is false for every show whose stream came back
            // clean, so nobody goes looking for a card they do not need.
            'needs_onsite' => $show->needsOnsite(),
            'written_off' => $show->isWrittenOff(),
            'recording_url' => $recording ? route('manage.recordings.edit', $recording) : null,
            'recording_count' => $show->recordings->count(),
            'update_url' => route('manage.shows.recording-plan', $show),
        ];
    }

    /**
     * Rows arrive in schedule order. Grouping by anything else keeps that order inside
     * each group, so a person's block still reads as their day.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortForGrouping(Collection $rows, string $group): Collection
    {
        return match ($group) {
            // Unassigned last: it is the pile to be handed out, not a person.
            'owner' => $rows->sortBy(fn (array $row) => [$row['owner'] === null ? 1 : 0, $row['owner'] ?? ''])->values(),
            'source' => $rows->sortBy(fn (array $row) => $row['source'] ?? '')->values(),
            default => $rows,
        };
    }

    /**
     * The counts along the top. Taken from the rows on screen rather than from a second
     * set of queries, so the tiles always describe what is being looked at.
     *
     * @param  Collection<int, Show>  $shows
     * @return array<int, array<string, mixed>>
     */
    private function summary(Collection $shows): array
    {
        $states = $shows->map(fn (Show $show) => $show->recordingState());
        $planned = $shows->where('publish_plan', 'yes');

        $count = fn (string $state) => $states->filter(fn (string $item) => $item === $state)->count();

        return [
            ['key' => 'total', 'label' => 'Shows', 'value' => $shows->count(), 'tone' => Status::IDLE],
            [
                'key' => 'undecided',
                'label' => 'Undecided',
                'value' => $shows->where('publish_plan', 'undecided')->count(),
                'tone' => Status::WARN,
                'filter' => ['plan', 'undecided'],
            ],
            [
                'key' => 'unassigned',
                'label' => 'No owner',
                'value' => $planned->whereNull('recording_owner_id')->count(),
                'tone' => Status::INFO,
                'filter' => ['owner', 'none'],
            ],
            [
                'key' => 'gaps',
                'label' => 'Missing',
                'value' => $shows->filter(fn (Show $show) => $show->isRecordingGap())->count(),
                'tone' => Status::DANGER,
                'filter' => ['state', 'gaps'],
            ],
            [
                'key' => 'needs_onsite',
                'label' => 'Needs onsite',
                'value' => $count('needs_onsite'),
                'tone' => Status::WARN,
                'filter' => ['state', 'needs_onsite'],
            ],
            [
                'key' => 'onsite',
                'label' => 'To import',
                'value' => $count('onsite'),
                'tone' => Status::INFO,
                'filter' => ['state', 'onsite'],
            ],
            [
                'key' => 'lost',
                'label' => 'Lost',
                'value' => $count('lost'),
                'tone' => Status::DANGER,
                'filter' => ['state', 'lost'],
            ],
            [
                'key' => 'to_archive',
                'label' => 'To archive',
                'value' => $shows->filter(fn (Show $show) => $show->needsMediaArchive())->count(),
                'tone' => Status::WARN,
                'filter' => ['state', 'not_archived'],
            ],
            [
                'key' => 'published',
                'label' => 'Published',
                'value' => $count('published'),
                'tone' => Status::OK,
                'filter' => ['state', 'published'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $group = (string) $request->query('group', 'day');

        return [
            'event' => $this->eventFilter($request),
            'search' => trim((string) $request->query('search', '')) ?: null,
            'source' => $request->query('source') ?: null,
            'day' => $request->query('day') ?: null,
            'plan' => $request->query('plan') ?: null,
            'owner' => $request->query('owner') ?: null,
            'state' => $request->query('state') ?: null,
            'mine' => $request->boolean('mine'),
            'show_archived' => $request->boolean('show_archived'),
            'group' => in_array($group, ['day', 'owner', 'source', 'none'], true) ? $group : 'day',
        ];
    }

    /**
     * @param  Builder<Show>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, User $user): void
    {
        /*
         * A chosen day carries its own run and is the more specific answer, so it wins
         * outright - otherwise a link to a day in a past run would come back empty
         * against the default.
         */
        if ($filters['event'] !== EventFilter::ALL && ! $filters['day']) {
            $filters['event'] === EventFilter::NONE
                ? $query->whereNull('event_id')
                : $query->where('event_id', $filters['event']);
        }

        if ($filters['search']) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $inner) => $inner
                ->where('title', 'like', $term)
                ->orWhereHas('source', fn (Builder $source) => $source->where('name', 'like', $term)));
        }

        if ($filters['source']) {
            $query->where('source_id', $filters['source']);
        }

        if ($filters['day']) {
            $query->whereDate('scheduled_start', $filters['day']);
        }

        if ($filters['plan']) {
            $query->where('publish_plan', $filters['plan']);
        }

        // Deliberately not the same as owner=<my id>: this one survives being sent to
        // someone else, which is what makes it a board rather than a report.
        if ($filters['mine']) {
            $query->where('recording_owner_id', $user->id);
        }

        if ($filters['owner']) {
            // 'none' is a real answer here, and the one the page is opened to find.
            $filters['owner'] === 'none'
                ? $query->whereNull('recording_owner_id')
                : $query->where('recording_owner_id', $filters['owner']);
        }

        $this->applyStateFilter($query, $filters['state']);
    }

    /**
     * The recording state is decided in PHP, because it reads a fallback chain rather
     * than a column. These narrow which rows are fetched to the ones that can possibly be
     * in the asked-for state; `recordingState()` still has the last word on what each row
     * reads.
     *
     * @param  Builder<Show>  $query
     */
    private function applyStateFilter(Builder $query, ?string $state): void
    {
        $writtenOff = fn (Builder $inner) => $inner
            ->where('stream_condition', 'lost')
            ->whereIn('onsite_status', ['none', 'unusable']);

        /*
         * The negation, spelled out rather than wrapped in whereNot. `NOT (a AND b)` is
         * null whenever either side is null, and both sides are null for every row nobody
         * has looked at yet - so the tidy version silently drops exactly the rows these
         * filters exist to surface.
         */
        $notWrittenOff = fn (Builder $inner) => $inner
            ->whereNull('stream_condition')
            ->orWhere('stream_condition', '!=', 'lost')
            ->orWhereNull('onsite_status')
            ->orWhereNotIn('onsite_status', ['none', 'unusable']);

        match ($state) {
            // Nothing to cut and no reason recorded for that. A write-off is excluded and
            // so is a chase in progress: both are accounted for.
            'gaps' => $query->where('publish_plan', 'yes')
                ->whereIn('status', ['ended', 'live'])
                ->whereDoesntHave('recordings')
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('stream_condition')
                    ->orWhere('stream_condition', 'ok'))
                // `null != 'received'` is null in SQL, so a plain negation would drop
                // every row nobody has looked at - which is most of them.
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('onsite_status')
                    ->orWhere('onsite_status', '!=', 'received')),
            'needs_onsite' => $query->whereNot('publish_plan', 'no')
                ->whereIn('stream_condition', Show::STREAM_FAILURES)
                ->whereDoesntHave('recordings')
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('onsite_status')
                    ->orWhere('onsite_status', '!=', 'received'))
                ->where($notWrittenOff),
            'onsite' => $query->where('onsite_status', 'received')->whereDoesntHave('recordings'),
            'lost' => $query->where($writtenOff)->whereDoesntHave('recordings'),
            'not_archived' => $query->whereNull('archive_pgm_at')
                ->whereIn('status', ['ended', 'live'])
                ->where($notWrittenOff),
            'unchecked' => $query->whereNull('stream_condition')->whereHas('recordings'),
            'no_recording' => $query->whereDoesntHave('recordings'),
            'published' => $query->whereHas('recordings', fn (Builder $inner) => $inner->where('is_published', true)),
            'unpublished' => $query->whereHas('recordings')
                ->whereDoesntHave('recordings', fn (Builder $inner) => $inner->where('is_published', true)),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceOptions(): array
    {
        return Source::ordered()
            ->get(['id', 'name'])
            ->map(fn (Source $source) => ['value' => (string) $source->id, 'label' => $source->name])
            ->all();
    }

    /**
     * Who a recording can be given to: anyone who could do something about it, which is
     * the same set the panel itself lets in. An account that cannot reach /manage cannot
     * be made responsible for a cut it has no way to make.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ownerOptions(): array
    {
        /*
         * The permission set is a json column, so which roles qualify is decided in PHP -
         * `like` over json is a Postgres type error, and the role table is a dozen rows.
         */
        $roleIds = Role::all()
            ->filter(fn (Role $role) => $role->hasPermission('stream.manage')
                || $role->hasPermission('admin.access')
                || $role->hasPermission('filament.access'))
            ->pluck('id');

        /*
         * Plus anyone already holding a row, qualified or not. A volunteer who loses the
         * role keeps the shows they were given, and dropping them from the list would
         * render those cells blank - the row would read as unassigned when it is not.
         */
        $assigned = Show::whereNotNull('recording_owner_id')->distinct()->pluck('recording_owner_id');

        return User::query()
            ->where(fn (Builder $user) => $user
                ->whereHas('roles', fn (Builder $role) => $role->whereIn('roles.id', $roleIds))
                ->orWhereIn('id', $assigned))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planOptions(): array
    {
        return array_map(
            fn (string $plan) => [
                'value' => $plan,
                'label' => Status::publishPlan($plan)['label'],
                'tone' => Status::publishPlan($plan)['tone'],
            ],
            Show::PUBLISH_PLANS,
        );
    }

    /**
     * Unchecked is the empty option rather than a stored value: a row nobody has watched
     * yet holds null, and having two ways to say that would only let them disagree. The
     * same goes for an onsite copy nobody has looked for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function streamOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => Status::streamCondition(null)['label']]],
            array_map(
                fn (string $condition) => [
                    'value' => $condition,
                    'label' => Status::streamCondition($condition)['label'],
                ],
                Show::STREAM_CONDITIONS,
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function onsiteOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => Status::onsiteStatus(null)['label']]],
            array_map(
                fn (string $status) => [
                    'value' => $status,
                    'label' => Status::onsiteStatus($status)['label'],
                ],
                Show::ONSITE_STATUSES,
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stateOptions(): array
    {
        return [
            ['value' => 'gaps', 'label' => 'Missing, no reason recorded'],
            ['value' => 'needs_onsite', 'label' => 'Needs the onsite copy'],
            ['value' => 'onsite', 'label' => 'Onsite master to import'],
            ['value' => 'lost', 'label' => 'Lost for good'],
            ['value' => 'not_archived', 'label' => 'Not on the archive FTP yet'],
            ['value' => 'unchecked', 'label' => 'Cut but not watched yet'],
            ['value' => 'no_recording', 'label' => 'Nothing cut yet'],
            ['value' => 'unpublished', 'label' => 'Cut but unpublished'],
            ['value' => 'published', 'label' => 'Published'],
        ];
    }

    /**
     * The run the page opens on.
     *
     * The plan is filed by run, not by calendar year: a run is what anybody means when
     * they say which year a show is from, and it is the unit this work is done in. It
     * opens on the run that is on - or the one that just finished, which is when most of
     * this accounting actually happens - rather than on every show that ever ran. `all`
     * switches the filter off and `none` is the pile of shows filed under no run, which
     * is what a programme imported before the calendar existed looks like.
     *
     * An installation that has never set the calendar up gets `all`, so it keeps the
     * shape it had before events existed.
     */
    private function defaultEvent(): string
    {
        return EventFilter::default(EventFilter::ALL);
    }

    /**
     * The run asked for, the default one if nothing was asked. Anything the list does
     * not offer is ignored rather than refused: this arrives from a query string, and a
     * mistyped link should land on the sensible default, not a 422.
     */
    private function eventFilter(Request $request): string
    {
        $event = (string) $request->query('event', $this->defaultEvent());

        return array_key_exists($event, EventFilter::options(withAll: true))
            ? $event
            : $this->defaultEvent();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventOptions(): array
    {
        return array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys(EventFilter::options(withAll: true)),
            array_values(EventFilter::options(withAll: true)),
        );
    }

    /**
     * The days the programme actually runs on, so the day filter offers the event's
     * dates rather than a date picker over all of time.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function dayOptions(array $filters): array
    {
        $query = Show::query()->orderBy('scheduled_start');

        if (! $filters['show_archived']) {
            $query->notArchived();
        }

        // Scoped to the run on screen, so the day list is that event's dates rather than
        // every date the installation has ever run.
        if ($filters['event'] !== EventFilter::ALL) {
            $filters['event'] === EventFilter::NONE
                ? $query->whereNull('event_id')
                : $query->where('event_id', $filters['event']);
        }

        return $query->get(['scheduled_start'])
            ->map(fn (Show $show) => $show->scheduled_start)
            ->filter()
            ->unique(fn ($date) => $date->format('Y-m-d'))
            ->map(fn ($date) => ['value' => $date->format('Y-m-d'), 'label' => $date->format('D j M')])
            ->values()
            ->all();
    }
}
