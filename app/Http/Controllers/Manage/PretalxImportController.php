<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\PretalxRoomSource;
use App\Models\Show;
use App\Models\Source;
use App\Services\PretalxImporter;
use App\Services\PretalxService;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use RuntimeException;

/**
 * Importing the programme from pretalx.
 *
 * pretalx is where the schedule is decided; this screen is where the parts of it that get
 * streamed become shows. Each pretalx room is pinned to one of our channels, and every
 * imported slot is remembered on the show, so the list shows what is already in and
 * offers only the rest. Deleting a show hands its slot back.
 *
 * Note that pretalx has no notion of a session running late: what is imported are planned
 * times, and keeping them honest afterwards is the planner's job.
 */
class PretalxImportController extends Controller
{
    public function index(Request $request, PretalxService $pretalx): Response
    {
        $this->authorize('create', Show::class);

        $configured = $pretalx->isConfigured();
        $error = null;
        $rooms = [];
        $slots = [];

        if ($configured) {
            try {
                $rooms = $pretalx->rooms();
                $slots = $pretalx->slots();
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                $rooms = [];
                $slots = [];
            }
        }

        $event = $pretalx->event();
        $mapping = $event !== null ? PretalxRoomSource::mapFor($event) : [];
        $imported = Show::whereNotNull('pretalx_slot_id')
            ->pluck('id', 'pretalx_slot_id');

        // Rooms pretalx knows about, plus any room a slot names that is not in the room
        // list (a private room the token cannot see, for instance).
        $roomNames = collect($rooms)->pluck('name', 'id');

        foreach ($slots as $slot) {
            if (! $roomNames->has($slot['room_id'])) {
                $roomNames->put($slot['room_id'], 'Room '.$slot['room_id']);
                $rooms[] = ['id' => $slot['room_id'], 'name' => 'Room '.$slot['room_id']];
            }
        }

        $sessionCounts = collect($slots)->countBy('room_id');

        /*
         * A con has far more rooms than channels - 46 of them here against a handful of
         * sources - and most hold nothing that is ever streamed. Only rooms with sessions
         * are offered, plus any room already mapped, so the mapping list stays readable.
         */
        $rooms = array_values(array_filter(
            $rooms,
            fn (array $room) => $sessionCounts->has($room['id']) || isset($mapping[$room['id']]),
        ));

        return inertia('Manage/Shows/Import', [
            'configured' => $configured,
            'event' => $event,
            'instance' => $pretalx->baseUrl(),
            'error' => $error,
            'settingsUrl' => route('manage.settings'),
            'sources' => Source::ordered()
                ->get(['id', 'name'])
                ->map(fn (Source $source) => ['value' => $source->id, 'label' => $source->name])
                ->all(),
            'rooms' => array_map(fn (array $room) => [
                'id' => $room['id'],
                'name' => $room['name'],
                'source_id' => $mapping[$room['id']] ?? null,
                'sessions' => $sessionCounts->get($room['id'], 0),
            ], $rooms),
            'slots' => array_map(fn (array $slot) => [
                'id' => $slot['id'],
                'title' => $slot['title'],
                'speakers' => $slot['speakers'],
                'room_id' => $slot['room_id'],
                'start' => $slot['start']->toIso8601String(),
                'end' => $slot['end']->toIso8601String(),
                'day' => $slot['start']->format('D j M'),
                'time' => $slot['start']->format('H:i').' - '.$slot['end']->format('H:i'),
                'past' => $slot['end']->isPast(),
                'showUrl' => isset($imported[$slot['id']])
                    ? route('manage.shows.edit', $imported[$slot['id']])
                    : null,
            ], $slots),
        ]);
    }

    /**
     * Save the room mapping and import the ticked slots. Both in one post: the mapping is
     * what decides where a slot can go, so a selection is only meaningful together with it.
     */
    public function store(Request $request, PretalxService $pretalx, PretalxImporter $importer): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $validated = $request->validate([
            'rooms' => ['array'],
            'rooms.*.id' => ['required', 'integer'],
            'rooms.*.name' => ['nullable', 'string', 'max:255'],
            'rooms.*.source_id' => ['nullable', 'integer', Rule::exists('sources', 'id')],
            'slots' => ['array'],
            'slots.*' => ['string', 'max:255'],
        ]);

        $event = $pretalx->event();

        if ($event === null) {
            Toast::flashDanger('Pretalx is not configured', 'Set the instance URL and event slug in Settings first.');

            return back();
        }

        $this->saveRooms($validated['rooms'] ?? [], $event);

        $slots = $validated['slots'] ?? [];

        if ($slots === []) {
            Toast::flashSuccess('Room mapping saved', 'No sessions were selected, so nothing was imported.');

            return back();
        }

        try {
            $result = $importer->import($slots, $event);
        } catch (RuntimeException $e) {
            Toast::flashDanger('Import failed', $e->getMessage());

            return back();
        }

        $this->reportImport($result);

        return back();
    }

    /**
     * Drop the cached schedule, for when pretalx published a new version mid-event.
     */
    public function refresh(PretalxService $pretalx): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $pretalx->forget();

        Toast::flashSuccess('Schedule reloaded', 'The next read comes straight from pretalx.');

        return back();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rooms
     */
    private function saveRooms(array $rooms, string $event): void
    {
        foreach ($rooms as $room) {
            PretalxRoomSource::updateOrCreate(
                ['event_slug' => $event, 'room_id' => (int) $room['id']],
                ['room_name' => $room['name'] ?? null, 'source_id' => $room['source_id'] ?? null],
            );
        }
    }

    /**
     * @param  array{imported: int, existing: int, unmapped: int, missing: int}  $result
     */
    private function reportImport(array $result): void
    {
        $skipped = [];

        if ($result['existing'] > 0) {
            $skipped[] = $result['existing'].' already imported';
        }

        if ($result['unmapped'] > 0) {
            $skipped[] = $result['unmapped'].' in a room with no channel';
        }

        if ($result['missing'] > 0) {
            $skipped[] = $result['missing'].' no longer in the pretalx schedule';
        }

        $detail = $skipped === [] ? null : 'Skipped: '.implode(', ', $skipped).'.';

        if ($result['imported'] === 0) {
            Toast::flashDanger('Nothing imported', $detail ?? 'None of the selected sessions could be imported.');

            return;
        }

        Toast::flashSuccess(
            $result['imported'].' '.str('session')->plural($result['imported']).' imported',
            trim(('They are scheduled and can be edited like any other show. '.($detail ?? ''))),
        );
    }
}
