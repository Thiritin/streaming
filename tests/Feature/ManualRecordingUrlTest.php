<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Models\Role;
use App\Models\User;
use App\Services\ArchivePlaylistService;
use App\Services\RecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Recordings registered from outside, with an `m3u8_url` typed in by hand.
 *
 * These predate the segment archive and must keep working alongside it. A cut is a
 * `(source, starts_at, ends_at)` view whose playlist the app renders per request; a
 * manual recording is just a URL someone pasted, pointing anywhere. `hasCut()` is the
 * only thing that separates them, and everything that treats a recording as a cut has
 * to be gated on it.
 *
 * Worth pinning because the failure mode is quiet. A manual recording pushed through
 * the archive path does not announce itself: it used to die on a TypeError that the
 * playlist controller relabelled as 410 "Gone", which reads as an expired archive for
 * a recording that is perfectly intact.
 */
class ManualRecordingUrlTest extends TestCase
{
    use RefreshDatabase;

    private function manual(array $overrides = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'Opening Ceremony 2019',
            'slug' => 'opening-ceremony-2019',
            'date' => '2019-08-15 12:00:00',
            'm3u8_url' => 'https://cdn.example.test/opening/index.m3u8',
            'is_published' => true,
        ], $overrides));
    }

    public function test_a_manual_recording_has_no_cut(): void
    {
        $recording = $this->manual();

        $this->assertFalse($recording->hasCut());
        $this->assertNull($recording->starts_at);
        $this->assertNull($recording->ends_at);
    }

    public function test_duration_is_read_over_http_from_the_pasted_url(): void
    {
        Http::fake([
            'cdn.example.test/*' => Http::response(
                "#EXTM3U\n#EXTINF:10.0,\na.ts\n#EXTINF:10.0,\nb.ts\n#EXT-X-ENDLIST\n", 200
            ),
        ]);

        $recording = $this->manual();
        app(RecordingService::class)->processRecording($recording);

        $this->assertSame(20, $recording->fresh()->duration);

        // The staged-playlist path exists for cuts, whose m3u8_url is an app route a
        // queue worker cannot fetch. A manual URL must still be fetched directly.
        Http::assertSent(fn ($request) => $request->url() === 'https://cdn.example.test/opening/index.m3u8');
    }

    public function test_the_pasted_url_is_never_rewritten(): void
    {
        Http::fake(['cdn.example.test/*' => Http::response("#EXTM3U\n#EXTINF:5.0,\na.ts\n", 200)]);

        $recording = $this->manual();
        app(RecordingService::class)->processRecording($recording);

        $this->assertSame(
            'https://cdn.example.test/opening/index.m3u8',
            $recording->fresh()->m3u8_url,
            'Processing must not replace a hand-entered URL with a generated route.',
        );
    }

    public function test_the_public_player_serves_the_pasted_url_verbatim(): void
    {
        $recording = $this->manual();

        $this->actingAs(User::factory()->create())
            ->get(route('recordings.show', $recording))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RecordingPlayer')
                ->where('recording.m3u8_url', 'https://cdn.example.test/opening/index.m3u8')
            );
    }

    public function test_the_archive_service_refuses_a_manual_recording_by_name(): void
    {
        $recording = $this->manual();

        // Deliberately not `expectException(Throwable::class)`, which passed here for
        // the wrong reason: with no source the slug was null and the call died inside
        // segmentsInRange() on a TypeError. Any unrelated fault would have satisfied it
        // equally. The refusal has to be the service's own, in its own words.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a cut from a source archive');

        app(ArchivePlaylistService::class)->renderMedia($recording, 'hd');
    }

    public function test_the_playlist_route_answers_gone_for_a_manual_recording(): void
    {
        $recording = $this->manual();

        $this->actingAs(User::factory()->create())
            ->get(route('recordings.playlist.media', [$recording->slug, 'hd']))
            ->assertStatus(410);
    }

    /**
     * A fault is a fault, not a 410.
     *
     * The controller used to catch `\Throwable` and answer 410 with the message
     * attached. Anything that went wrong in rendering - the `TypeError` a manual
     * recording used to cause, a driver error, a bug - came back as "Gone", which reads
     * as "the archive expired" and was never logged. Real breakage looked like ordinary
     * retention.
     *
     * Now only the service's own `RuntimeException` means Gone; everything else is a
     * logged 500. Asserting on the response body cannot show this - production runs
     * `APP_DEBUG=false`, which discards the abort message, and with debug on Ignition
     * renders enough source context to match almost any string. The status code is the
     * part that carries the meaning.
     */
    public function test_an_unexpected_render_fault_is_a_logged_500_not_a_410(): void
    {
        Log::spy();

        $this->mock(ArchivePlaylistService::class, function ($mock) {
            $mock->shouldReceive('renderMedia')->andThrow(new \TypeError('internal detail'));
            $mock->shouldReceive('renditions')->andReturn(['sd', 'hd', 'fhd', 'source']);
        });

        $recording = $this->manual();

        $this->actingAs(User::factory()->create())
            ->get(route('recordings.playlist.media', [$recording->slug, 'hd']))
            ->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Recording playlist render failed'))
            ->once();
    }

    /**
     * Through the real controller, not `$recording->update()`.
     *
     * The guard being tested lives in `Manage\RecordingController::update()`, which
     * rebuilds only `if ($recording->hasCut())`. Asserting against a model save proves
     * nothing about it: a controller regression that rebuilt unconditionally would
     * still pass, because the model never consults that branch.
     */
    public function test_saving_a_manual_recording_does_not_trigger_an_archive_rebuild(): void
    {
        $recording = $this->manual();
        $this->assertFalse($recording->hasCut());

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator', 'permissions' => ['admin.access'], 'priority' => 100]
        ));

        $this->actingAs($admin)
            ->put(route('manage.recordings.update', $recording), [
                'title' => 'Renamed',
                'slug' => $recording->slug,
                'date' => '2019-08-15T12:00',
                'm3u8_url' => 'https://cdn.example.test/opening/index.m3u8',
                'is_published' => true,
            ])
            ->assertRedirect();

        $fresh = $recording->fresh();

        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('https://cdn.example.test/opening/index.m3u8', $fresh->m3u8_url);

        // A rebuild would have moved this off the schema default - to `ready`, or to
        // `failed` with a build_error, which is what pushing a manual recording through
        // the archive path actually produces since no segments cover it.
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->build_error);
    }
}
