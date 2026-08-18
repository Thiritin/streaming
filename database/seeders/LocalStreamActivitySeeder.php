<?php

namespace Database\Seeders;

use App\Models\Message;
use App\Models\Role;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The bits of a stream page that only exist once people are watching: a chat
 * backlog, viewer counts and a boop total.
 *
 * Without this the local player is a video in an empty room, which is the one
 * state that never needs designing for. Safe to re-run: the seeded viewers are
 * matched on their `sub`, and chat is only filled for rooms that are still quiet.
 */
class LocalStreamActivitySeeder extends Seeder
{
    private const VIEWERS = [
        ['name' => 'Nia Fennhart', 'role' => 'moderator'],
        ['name' => 'Ruff Patrol', 'role' => 'moderator'],
        ['name' => 'Kettle', 'role' => 'staff'],
        ['name' => 'Sable', 'role' => 'supersponsor'],
        ['name' => 'Mikko', 'role' => 'sponsor'],
        ['name' => 'Pawel', 'role' => 'attendee'],
        ['name' => 'Grumpy Otter', 'role' => 'attendee'],
        ['name' => 'Toast', 'role' => 'attendee'],
        ['name' => 'Vix', 'role' => 'attendee'],
        ['name' => 'Halcyon', 'role' => 'attendee'],
        ['name' => 'Birb', 'role' => 'digital-pass'],
        ['name' => 'Quill', 'role' => 'digital-pass'],
    ];

    private const CHATTER = [
        'audio sounds great from here',
        'first time watching from home, this is lovely',
        ':wave:',
        'who is on next?',
        'that transition was clean',
        'the stream is buttery smooth today',
        'boop',
        'BOOP',
        'someone tell the camera op they are doing great',
        'is the schedule on the site up to date?',
        'aaaa I love this part',
        'chat, behave :3',
        'watching from the hotel lobby',
        'my cat is also watching now',
        'that fursuit is incredible',
        'how many people are in the room right now?',
        'wooo!!',
        'the lighting looks so much better this year',
        'brb making tea, do not let anything happen',
        'back, what did I miss',
        'quality bumped to 1080 and it did not even stutter',
        'this is the third year I have watched remotely and it keeps getting better',
        ':heart:',
        'anyone else getting a tiny bit of audio delay? seems fine now',
        'that is a lot of boops on screen',
        'greetings from Finland',
        'greetings from very much not Finland',
        'the panel starts in half an hour I think',
        'thank you tech crew!!',
        'clap.gif',
    ];

    private const ANNOUNCEMENTS = [
        'Chat rules: be kind, no spoilers for the closing ceremony, mods are around.',
        'Stream having trouble? Reload once before reporting it, the edge may have moved.',
    ];

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command->warn('LocalStreamActivitySeeder only runs in local.');

            return;
        }

        $viewers = $this->viewers();
        $shows = Show::with('source')->whereIn('status', ['live', 'ended'])->get();

        if ($shows->isEmpty()) {
            $this->command->warn('No live or ended shows to add activity to. Run ShowSeeder first.');

            return;
        }

        foreach ($shows as $show) {
            $live = $show->status === 'live';

            $show->forceFill([
                'viewer_count' => $live ? random_int(80, 2400) : 0,
                'peak_viewer_count' => max((int) $show->peak_viewer_count, random_int(120, 3200)),
                // Enough to look lived-in, low enough that one more click is still
                // visible in the digits.
                'boop_count' => $live ? random_int(150, 4200) : random_int(40, 2600),
            ])->save();
        }

        $liveShows = $shows->where('status', 'live');

        foreach ($liveShows->pluck('source')->filter()->unique('id') as $source) {
            // A handful of messages from hand testing is not a backlog; top it up
            // anyway. A room that already has one is left alone.
            if (Message::where('source_id', $source->id)->count() >= 40) {
                $this->command->info("Chat already busy for {$source->name}, skipping.");

                continue;
            }

            $this->chat($source->id, $viewers);

            $this->command->info("Chat backlog seeded for {$source->name}.");
        }

        $this->command->info('Viewers, boops and chat seeded for '.$shows->count().' shows.');
    }

    /**
     * Watchers to attribute chat to. Their `sub` marks them as seed data, the
     * same way DebugController marks the personas it creates.
     */
    private function viewers(): Collection
    {
        return collect(self::VIEWERS)->map(function (array $viewer) {
            $user = User::updateOrCreate(
                ['sub' => 'seed|'.Str::slug($viewer['name'])],
                [
                    'name' => $viewer['name'],
                    'reg_id' => random_int(1000, 9999),
                ]
            );

            $role = Role::where('slug', $viewer['role'])->first();

            if ($role && ! $user->roles()->where('roles.id', $role->id)->exists()) {
                $user->roles()->attach($role->id);
            }

            return $user;
        });
    }

    /**
     * A backlog worth scrolling: 45 minutes of messages, thinning out towards
     * the top, with a couple of replies and the pinned-style announcements.
     */
    private function chat(int $sourceId, Collection $viewers): void
    {
        $at = now()->subMinutes(45);
        $recent = [];

        foreach (self::ANNOUNCEMENTS as $index => $announcement) {
            Message::create([
                'source_id' => $sourceId,
                'user_id' => null,
                'message' => $announcement,
                'type' => 'announcement',
                'is_command' => false,
                'created_at' => $at->copy()->addMinutes($index),
                'updated_at' => $at->copy()->addMinutes($index),
            ]);
        }

        for ($i = 0; $i < 70; $i++) {
            $at = $at->addSeconds(random_int(15, 60));

            $replyTo = ($recent !== [] && random_int(1, 6) === 1)
                ? $recent[array_rand($recent)]
                : null;

            $message = Message::create([
                'source_id' => $sourceId,
                'user_id' => $viewers->random()->id,
                'message' => self::CHATTER[array_rand(self::CHATTER)],
                'type' => 'user',
                'is_command' => false,
                'reply_to_id' => $replyTo,
                'created_at' => $at->copy(),
                'updated_at' => $at->copy(),
            ]);

            $recent[] = $message->id;
            $recent = array_slice($recent, -10);
        }
    }
}
