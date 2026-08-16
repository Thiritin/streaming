<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Models\User;
use App\Services\ArchivePlaylistService;
use App\Services\RecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
 * Worth pinning because the failure mode is quiet: a manual recording routed through
 * ArchivePlaylistService does not error, it resolves to "no archived segments cover
 * that range" and looks like data loss.
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

    public function test_the_archive_playlist_route_does_not_claim_a_manual_recording(): void
    {
        $recording = $this->manual();

        // renderMedia would resolve a source and a PDT range, neither of which exists
        // here. It must refuse rather than return an empty playlist.
        $this->expectException(\Throwable::class);
        app(ArchivePlaylistService::class)->renderMedia($recording, 'hd');
    }

    public function test_saving_a_manual_recording_does_not_trigger_an_archive_rebuild(): void
    {
        $recording = $this->manual();

        // Mirrors the guard in Manage\RecordingController::update.
        $this->assertFalse($recording->hasCut());

        $recording->update(['title' => 'Renamed']);

        $this->assertSame('Renamed', $recording->fresh()->title);
        $this->assertSame('https://cdn.example.test/opening/index.m3u8', $recording->fresh()->m3u8_url);

        // Still the schema default. A rebuild would have moved it to `ready` (or to
        // `failed`, which is what a manual recording pushed through the archive path
        // would actually produce, since no segments cover it).
        $this->assertSame('draft', $recording->fresh()->status);
        $this->assertNull($recording->fresh()->build_error);
    }
}
