<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Recording;
use App\Models\Show;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Settings;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * The convention calendar: one row per run, with the days it covers.
 *
 * Two things read it and nothing else does. The site is in its live state while
 * today falls inside a window, and outside one the front page is the archive.
 * Everything else - the show gate, who may watch what - is unchanged by it.
 */
class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Event::class);

        $table = Table::make(Event::query()->withCount(['shows', 'recordings']))
            ->name('events')
            ->columns([
                Column::text('name', 'Name')->searchable('name')->sortable(),
                Column::copyable('slug', 'Slug')->searchable('slug'),
                Column::badge('state', 'State'),
                Column::text('dates', 'Dates')->sortable('starts_on'),
                Column::number('shows_count', 'Shows'),
                Column::number('recordings_count', 'Recordings'),
            ])
            ->defaultSort('starts_on', 'desc')
            ->rows(fn (Event $event) => [
                'name' => $event->name,
                'slug' => $event->slug,
                'state' => $this->stateBadge($event),
                'dates' => $event->dateRange(),
                'shows_count' => $event->shows_count,
                'recordings_count' => $event->recordings_count,
            ])
            ->recordUrl(fn (Event $event) => route('manage.events.edit', $event))
            ->rowActions(fn (Event $event) => $this->rowActions($event))
            ->pageActions($this->pageActions());

        return inertia('Manage/Events/Index', [
            'table' => $table->toArray($request),
            'navigation' => app(Settings::class)->navigation(),
            'current' => $this->summary(Event::current()),
            'next' => $this->summary(Event::next()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Event::class);

        return inertia('Manage/Events/Form', [
            'navigation' => app(Settings::class)->navigation(),
            'event' => null,
            'defaults' => [
                'name' => '',
                'slug' => '',
                'starts_on' => now()->format('Y-m-d'),
                'ends_on' => now()->addDays(4)->format('Y-m-d'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Event::class);

        $event = Event::create($this->validated($request));

        Toast::flashSuccess('Event created', "{$event->name} runs {$event->dateRange()}.");

        return to_route('manage.events.edit', $event);
    }

    public function edit(Event $event): Response
    {
        $this->authorize('view', $event);

        $event->loadCount(['shows', 'recordings']);

        return inertia('Manage/Events/Form', [
            'navigation' => app(Settings::class)->navigation(),
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'starts_on' => $event->starts_on->format('Y-m-d'),
                'ends_on' => $event->ends_on->format('Y-m-d'),
                'date_range' => $event->dateRange(),
                'state' => $this->stateBadge($event),
                'shows_count' => $event->shows_count,
                'recordings_count' => $event->recordings_count,
                // What "Match by date" would pick up if it were pressed now, so the
                // button says how much it is about to move rather than being a leap.
                'unfiled' => $this->unfiledCounts($event),
                'match_url' => route('manage.events.match', $event),
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->rowActions($event, includeEdit: false),
            ),
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $event->update($this->validated($request, $event));

        Toast::flashSuccess('Event updated');

        return back();
    }

    /**
     * File everything that happened inside this window and is not filed anywhere yet.
     *
     * This is what makes an archive that predates the calendar filed under it: a show
     * or a recording created before the event existed had nothing to inherit, and
     * nobody is opening a hundred forms. Only unfiled rows are touched, so a run
     * whose window overlaps another cannot steal its programme.
     */
    public function match(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $shows = Show::whereNull('event_id')
            ->whereBetween('scheduled_start', [$event->startsAt(), $event->endsAt()])
            ->update(['event_id' => $event->id]);

        $recordings = Recording::whereNull('event_id')
            ->whereNull('show_id')
            ->whereBetween('date', [$event->startsAt(), $event->endsAt()])
            ->update(['event_id' => $event->id]);

        if ($shows === 0 && $recordings === 0) {
            Toast::flashWarning('Nothing to file', "Nothing unfiled falls inside {$event->dateRange()}.");

            return back();
        }

        Toast::flashSuccess(
            'Filed by date',
            "{$shows} show(s) and {$recordings} recording(s) are now part of {$event->name}.",
        );

        return back();
    }

    /**
     * Deleting frees every show and recording that carried it. Nothing goes dark:
     * the column is nullable and nothing reads it for access. What does change is
     * the front page, which is in its live state only while a window is open.
     */
    public function destroy(Event $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        $name = $event->name;
        $event->delete();

        Toast::flashSuccess('Event deleted', "Shows and recordings that were part of {$name} are now unfiled.");

        return to_route('manage.events.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Event $event = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('events', 'slug')->ignore($event?->id),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ], [], [
            'starts_on' => 'start date',
            'ends_on' => 'end date',
        ]);
    }

    /**
     * Where this run stands against today. Derived, never stored: an event that has
     * finished is one whose last day has passed, and a column saying otherwise could
     * only be wrong.
     *
     * @return array<string, mixed>
     */
    private function stateBadge(Event $event): array
    {
        if ($event->covers(now())) {
            return Status::make('On now', Status::LIVE, 'signal');
        }

        if ($event->hasEnded()) {
            return Status::make('Finished', Status::IDLE, 'archive');
        }

        return Status::make('Upcoming', Status::INFO, 'calendar');
    }

    /**
     * What falls inside the window and is filed nowhere.
     *
     * @return array{shows: int, recordings: int}
     */
    private function unfiledCounts(Event $event): array
    {
        return [
            'shows' => Show::whereNull('event_id')
                ->whereBetween('scheduled_start', [$event->startsAt(), $event->endsAt()])
                ->count(),
            'recordings' => Recording::whereNull('event_id')
                ->whereNull('show_id')
                ->whereBetween('date', [$event->startsAt(), $event->endsAt()])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summary(?Event $event): ?array
    {
        if (! $event) {
            return null;
        }

        return [
            'name' => $event->name,
            'dates' => $event->dateRange(),
            'url' => route('manage.events.edit', $event),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Event $event, bool $includeEdit = true): array
    {
        // The edit page is already the edit page; a button back to it is noise.
        $actions = $includeEdit
            ? [Action::link('edit', 'Edit', route('manage.events.edit', $event))->icon('pencil')]
            : [];

        if (request()->user()->can('delete', $event)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.events.destroy', $event))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Delete event',
                    "Shows and recordings that were part of {$event->name} keep everything else and become unfiled.",
                    'Delete',
                );
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', Event::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Event', route('manage.events.create'))->icon('plus'),
        ];
    }
}
