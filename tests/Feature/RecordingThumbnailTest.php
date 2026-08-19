<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Services\RecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordingThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private function recording(): Recording
    {
        return Recording::withoutEvents(fn () => Recording::create([
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony',
            'date' => now(),
            'duration' => 600,
            'm3u8_url' => 'https://cdn.example.test/opening/index.m3u8',
            'is_published' => true,
        ]));
    }

    /**
     * The playlist ffmpeg is handed is a local file whose segments are absolute https
     * URLs, and ffmpeg's whitelist for a file input is file,crypto,data. Without this
     * every segment is refused before it is fetched, which is why a cut used to end up
     * with a duration - parsed out of the playlist text - and never a thumbnail.
     */
    public function test_ffmpeg_may_follow_the_playlist_out_to_https_segments(): void
    {
        Storage::fake('s3');
        Process::fake();

        app(RecordingService::class)->generateThumbnail($this->recording());

        Process::assertRan(function ($process) {
            $command = $process->command;

            if (! is_array($command) || $command[0] !== 'ffmpeg') {
                return false;
            }

            $at = array_search('-protocol_whitelist', $command, true);

            return $at !== false
                && str_contains($command[$at + 1], 'https')
                // Input option: after -i it configures nothing.
                && $at < array_search('-i', $command, true);
        });
    }

    public function test_a_generated_thumbnail_is_stored_privately(): void
    {
        Storage::fake('s3');
        Process::fake();

        $recording = $this->recording();

        // Stand the frame up where ffmpeg would have left it, since Process is faked and
        // nothing actually writes one.
        $temp = storage_path('app/temp');
        if (! is_dir($temp)) {
            mkdir($temp, 0755, true);
        }

        // generateThumbnail names the file from the clock, so freeze it and write the
        // same name it is about to look for.
        $this->travelTo(now());
        $filename = 'recording_'.$recording->id.'_'.now()->format('YmdHis').'.jpg';
        file_put_contents($temp.'/'.$filename, 'not really a jpeg');

        $path = app(RecordingService::class)->generateThumbnail($recording);

        $this->assertSame('recordings/thumbnails/'.$filename, $path);
        Storage::disk('s3')->assertExists($path);
        $this->assertSame('private', Storage::disk('s3')->getVisibility($path));
    }
}
