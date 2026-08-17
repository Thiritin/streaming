<?php

namespace Tests\Feature\Manage;

use App\Models\Recording;
use App\Models\Role;
use App\Models\Source;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the cut editor's preview endpoint actually serves, as opposed to what was asked for.
 *
 * A preview playlist names every segment in its range, so its size is linear in the span and
 * an unbounded request is a denial of service against this endpoint. The cap is therefore
 * correct - but it used to apply in silence, answering 200 with a quarter of a long range and
 * no indication that anything had been dropped.
 *
 * That is worse than an error. The editor believed it held the whole archive, so seeking past
 * the truncation point moved the playhead to an instant the media element had no frames for,
 * the video pinned at its duration, and the next timeupdate dragged the playhead back. From
 * the outside it looked exactly like a recording that stopped hours early, which sends you
 * hunting through the uploader and S3 for data that was never missing.
 */
class RecordingPreviewRangeTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-08-17 02:00:00';

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('stream.archive_disk', 'dvr'));

        $this->source = Source::factory()->create(['slug' => 'main']);
        $this->writeArchive(hours: 8);
    }

    /**
     * Enough of a real archive for the range to resolve: an empty one raises instead, and
     * would hide whatever the range logic did.
     */
    private function writeArchive(int $hours): void
    {
        $disk = Storage::disk(config('stream.archive_disk', 'dvr'));
        $start = CarbonImmutable::parse(self::START, 'UTC');
        $seq = 0;

        for ($hour = 0; $hour < $hours; $hour++) {
            $hourStart = $start->addHours($hour);
            $lines = ['#EXTM3U', '#EXT-X-VERSION:6'];

            // Two per hour is plenty. This is about which hours are reachable, not density.
            for ($i = 0; $i < 2; $i++) {
                $pdt = $hourStart->addMinutes($i * 30);
                $lines[] = '#EXT-X-ARCHIVE-SEQ:'.$seq;
                $lines[] = '#EXT-X-PROGRAM-DATE-TIME:'.$pdt->toIso8601ZuluString();
                $lines[] = '#EXTINF:2.000,';
                $lines[] = "main_hd_{$seq}.ts";
                $seq++;
            }

            $disk->put(
                'archive/main/'.$hourStart->format('Ymd/H').'/index.m3u8',
                implode("\n", $lines)."\n",
            );
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator', 'permissions' => ['admin.access'], 'priority' => 100]
        ));

        return $admin;
    }

    private function recording(): Recording
    {
        return Recording::create([
            'source_id' => $this->source->id,
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony',
            'date' => self::START,
            'starts_at' => self::START,
            'ends_at' => CarbonImmutable::parse(self::START, 'UTC')->addHour(),
            'archive_prefix' => 'archive/main',
            'is_published' => false,
        ]);
    }

    private function preview(string $from, string $to)
    {
        return $this->actingAs($this->admin())->get(route(
            'manage.recordings.preview',
            [$this->recording(), 'from' => $from, 'to' => $to, 'rendition' => 'hd'],
        ));
    }

    #[Test]
    public function a_range_within_the_cap_is_served_whole_and_says_so(): void
    {
        $from = CarbonImmutable::parse(self::START, 'UTC');
        $to = $from->addHours(2);

        $response = $this->preview($from->toIso8601String(), $to->toIso8601String());

        $response->assertOk();
        $response->assertHeader('X-Preview-Truncated', 'false');

        $this->assertTrue(
            CarbonImmutable::parse($response->headers->get('X-Preview-To'))->eq($to),
            'A range inside the cap must be served exactly as asked for.',
        );
    }

    /**
     * The regression this file exists for. The response has to admit the truncation, because
     * the client maps wall clock onto the media element and cannot do that correctly against
     * a range it only assumes it received.
     */
    #[Test]
    public function an_over_long_range_is_capped_and_reports_the_range_it_served(): void
    {
        $from = CarbonImmutable::parse(self::START, 'UTC');
        $to = $from->addHours(8);

        $response = $this->preview($from->toIso8601String(), $to->toIso8601String());

        $response->assertOk();
        $response->assertHeader('X-Preview-Truncated', 'true');

        $served = CarbonImmutable::parse($response->headers->get('X-Preview-To'));

        $this->assertTrue($served->eq($from->addHours(4)), 'The cap is four hours from the start.');
        $this->assertTrue($served->lt($to), 'The served range must be shorter than the one requested.');

        // And the truncation is real, not just advertised: nothing past the cap is listed.
        $this->assertStringNotContainsString('main_hd_10.ts', $response->getContent());
    }

    #[Test]
    public function a_reversed_range_is_rejected_rather_than_rendered_empty(): void
    {
        $from = CarbonImmutable::parse(self::START, 'UTC');

        $this->preview($from->addHours(3)->toIso8601String(), $from->toIso8601String())
            ->assertStatus(422);
    }
}
