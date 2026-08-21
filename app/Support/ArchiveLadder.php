<?php

namespace App\Support;

/**
 * The one description of what an archived rendition is.
 *
 * Three things produce archive segments: the live transcoder (docker/ffmpeg-hls), the
 * offline import script, and the import CLI on an editor's laptop. They have to agree on
 * segment length, on the bitrates, and on what a segment is called, or a cut assembled
 * from their output plays back as a broken ladder.
 *
 * Live is a container this app does not run, so it keeps its own copy and always will.
 * Everything else reads this class - the CLI over the API, so a client that shipped
 * months ago encodes to today's ladder rather than the one it was built against.
 */
final class ArchiveLadder
{
    /**
     * Segment length. Not a preference: the hour indexes, the live rewind window and the
     * uploader's catch-up all assume it, and a cut is only ever accurate to one segment.
     */
    public const SEGMENT_SECONDS = 2;

    /**
     * Above this, the bottom rung is halved rather than encoded at source rate. 50p inside
     * 1500 kbps spends the budget on temporal resolution nobody watching 480p is short of.
     */
    public const SD_FPS_CEILING = 30;

    /**
     * Ascending quality, matching the live transcoder's var_stream_map.
     *
     * `bandwidth` and `resolution` are what a master playlist advertises. The rest is what
     * an encoder needs to produce the rung, and is only read by importers - the live
     * transcoder's own copy lives in stream-manager.sh.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function renditions(): array
    {
        return [
            'sd' => [
                'bandwidth' => 1_500_000,
                'resolution' => '854x480',
                'width' => 854,
                'height' => 480,
                'video_bitrate' => '1500k',
                'maxrate' => '2000k',
                'bufsize' => '3000k',
                'profile' => 'baseline',
                'preset' => 'veryfast',
                'audio_bitrate' => '128k',
                'halve_frame_rate' => true,
            ],
            'hd' => [
                'bandwidth' => 3_500_000,
                'resolution' => '1280x720',
                'width' => 1280,
                'height' => 720,
                'video_bitrate' => '3500k',
                'maxrate' => '4000k',
                'bufsize' => '8000k',
                'profile' => 'main',
                'preset' => 'veryfast',
                'audio_bitrate' => '160k',
                'halve_frame_rate' => false,
            ],
            'fhd' => [
                'bandwidth' => 6_000_000,
                'resolution' => '1920x1080',
                'width' => 1920,
                'height' => 1080,
                'video_bitrate' => '6000k',
                'maxrate' => '6500k',
                'bufsize' => '13000k',
                'profile' => 'main',
                // Offline encodes are not racing a live stream, so the top rung can afford
                // a slower preset than the transcoder's `faster`.
                'preset' => 'slow',
                'audio_bitrate' => '192k',
                'halve_frame_rate' => false,
            ],
        ];
    }

    /**
     * What an importing client is told to produce.
     *
     * Deliberately not the index format. A client reports segment durations and the server
     * writes the hour indexes itself, so the archive's own file format is never something
     * a shipped binary can get wrong or fall behind on.
     *
     * @return array<string, mixed>
     */
    public static function recipe(): array
    {
        return [
            'segment_seconds' => self::SEGMENT_SECONDS,
            'sd_fps_ceiling' => self::SD_FPS_CEILING,
            'renditions' => self::renditions(),
        ];
    }
}
