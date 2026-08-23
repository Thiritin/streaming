<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Recording;
use App\Models\Show;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Two runs of the convention: this year's, wrapped around the seeded programme so
 * the front page comes up in its live state, and last year's, so the archive has a
 * second collection to file things under.
 *
 * Shows and recordings are then spread across the two at random. That is deliberately
 * not "everything seeded today belongs to this year": the archive page is built around
 * more than one event existing, and a seed where every recording lands in the same one
 * hides every bug in the chip bar, the split on the front page and the inheritance
 * from show to recording.
 *
 * Runs after ShowSeeder and RecordingSeeder. Nothing auto-assigns here - Show::creating
 * fills the event in from the date, but the events do not exist yet when those seeders
 * run, so this seeder writes the column itself.
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();

        /*
         * The window covers the day before the programme through three days after it,
         * so `Event::current()` answers on a fresh seed whatever hour it is run at and
         * the seeded shows all fall inside.
         */
        $current = Event::updateOrCreate(
            ['slug' => $today->year.'-convention'],
            [
                'name' => $today->year.' Convention',
                'starts_on' => $today->clone()->subDay(),
                'ends_on' => $today->clone()->addDays(3),
            ],
        );

        $previous = Event::updateOrCreate(
            ['slug' => ($today->year - 1).'-convention'],
            [
                'name' => ($today->year - 1).' Convention',
                'starts_on' => $today->clone()->subYear()->subDay(),
                'ends_on' => $today->clone()->subYear()->addDays(3),
            ],
        );

        $events = [$current->id, $previous->id];

        $shows = Show::query()->get()->each(
            fn (Show $show) => $show->forceFill(['event_id' => $events[array_rand($events)]])->save(),
        );

        // Written directly rather than left to inherit: the seeded recordings carry no
        // show, so there is nothing for them to inherit from.
        $recordings = Recording::query()->get()->each(
            fn (Recording $recording) => $recording->forceFill(['event_id' => $events[array_rand($events)]])->save(),
        );

        $this->command->info(
            "Seeded 2 events. {$shows->count()} show(s) and {$recordings->count()} recording(s) spread across them.",
        );
    }
}
