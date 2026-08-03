<?php

namespace App\Services;

use App\Models\PretalxRoomSource;
use App\Models\Show;
use Illuminate\Support\Str;

/**
 * Turns pretalx slots into shows.
 *
 * A slot is imported once and only once: the show carries the slot id, and the unique
 * index on it is what makes a second import a no-op rather than a duplicate. Deleting the
 * show releases the slot, which is the intended way to redo an import after the programme
 * team moved something.
 *
 * Times, title and abstract are copied as they stand in pretalx. Everything the streaming
 * side owns (auto mode, recording, access) is left at its default and edited on the show.
 */
class PretalxImporter
{
    public function __construct(private readonly PretalxService $pretalx) {}

    /**
     * Import the given slots onto the channels their rooms are mapped to.
     *
     * @param  array<int, string>  $slotIds
     * @return array{imported: int, existing: int, unmapped: int, missing: int}
     */
    public function import(array $slotIds, string $eventSlug): array
    {
        $wanted = array_flip(array_map('strval', $slotIds));
        $rooms = PretalxRoomSource::mapFor($eventSlug);

        $slots = array_filter(
            $this->pretalx->slots(),
            fn (array $slot) => isset($wanted[$slot['id']]),
        );

        $result = [
            'imported' => 0,
            'existing' => 0,
            'unmapped' => 0,
            // Selected in the browser but gone from pretalx by the time we posted.
            'missing' => count($wanted) - count($slots),
        ];

        $taken = Show::whereIn('pretalx_slot_id', array_keys($wanted))
            ->pluck('pretalx_slot_id')
            ->flip();

        foreach ($slots as $slot) {
            if ($taken->has($slot['id'])) {
                $result['existing']++;

                continue;
            }

            $sourceId = $rooms[$slot['room_id']] ?? null;

            if ($sourceId === null) {
                $result['unmapped']++;

                continue;
            }

            $this->show($slot, $sourceId);
            $result['imported']++;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function show(array $slot, int $sourceId): Show
    {
        return Show::create([
            'title' => Str::limit($slot['title'], 250, ''),
            'slug' => $this->slug($slot),
            'description' => $slot['description'],
            'source_id' => $sourceId,
            'scheduled_start' => $slot['start'],
            'scheduled_end' => $slot['end'],
            'status' => 'scheduled',
            'auto_mode' => false,
            'required_roles' => [],
            'pretalx_slot_id' => $slot['id'],
        ]);
    }

    /**
     * Same shape the model builds for a hand-made show, but made unique here: a con runs
     * the same title in two rooms at once often enough that a clash is normal, and the
     * slug column is unique.
     *
     * @param  array<string, mixed>  $slot
     */
    private function slug(array $slot): string
    {
        $base = Str::limit(Str::slug($slot['title'].'-'.$slot['start']->format('Y-m-d')), 240, '');
        $base = $base !== '' ? $base : 'session-'.$slot['id'];
        $slug = $base;

        for ($suffix = 2; Show::where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
