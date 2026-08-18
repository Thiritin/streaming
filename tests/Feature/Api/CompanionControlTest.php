<?php

namespace Tests\Feature\Api;

use App\Enum\SourceStatusEnum;
use App\Events\ShowEnded;
use App\Events\ShowWentLive;
use App\Models\BrandingSetting;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The contract a control surface is wired against: two buttons and a status poll.
 */
class CompanionControlTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-control-key';

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ShowWentLive::class, ShowEnded::class]);

        config(['stream.control_key' => self::KEY]);

        $this->source = Source::create([
            'name' => 'Main Stage',
            'slug' => 'main-stage',
            'stream_key' => 'test_stream_key',
            'status' => SourceStatusEnum::ONLINE,
        ]);
    }

    private function headers(?string $token = null): array
    {
        return ['X-Companion-Token' => $token ?? self::KEY];
    }

    private function url(string $action, ?Source $source = null): string
    {
        return '/api/companion/'.($source ?? $this->source)->slug.'/'.$action;
    }

    private function showOn(Source $source, array $attributes = []): Show
    {
        return Show::factory()->create(array_merge([
            'source_id' => $source->id,
            'status' => 'scheduled',
        ], $attributes));
    }

    public function test_status_requires_the_control_key(): void
    {
        $this->getJson($this->url('status'))->assertStatus(401);
        $this->getJson($this->url('status'), $this->headers('nope'))->assertStatus(401);
    }

    /**
     * A fresh install has no key set, and an empty key must not mean "anything goes":
     * a surface that sends nothing would otherwise be able to put a show on air.
     */
    public function test_an_unset_control_key_leaves_the_api_closed(): void
    {
        config(['stream.control_key' => null]);

        $this->getJson($this->url('status'))->assertStatus(401);
        $this->getJson($this->url('status'), $this->headers(''))->assertStatus(401);
        $this->getJson($this->url('status'), $this->headers(self::KEY))->assertStatus(401);
    }

    /**
     * Rotating the key happens at /manage > Settings, so a saved one has to beat the
     * environment fallback the host booted with.
     */
    public function test_a_saved_control_key_wins_over_the_environment(): void
    {
        BrandingSetting::setValue('control_key', 'rotated-control-key');

        $this->getJson($this->url('status'), $this->headers(self::KEY))->assertStatus(401);
        $this->getJson($this->url('status'), $this->headers('rotated-control-key'))->assertSuccessful();
    }

    public function test_an_unknown_stream_name_is_a_not_found(): void
    {
        $this->getJson('/api/companion/no-such-source/status', $this->headers())->assertStatus(404);
    }

    /**
     * One key, many sources: the same surface credentials drive whichever stream name is
     * in the URL, and each connection only ever touches its own source.
     */
    public function test_the_same_key_drives_a_second_source(): void
    {
        $other = Source::create([
            'name' => 'Second Stage',
            'slug' => 'second-stage',
            'stream_key' => 'second_stream_key',
            'status' => SourceStatusEnum::ONLINE,
        ]);
        $show = $this->showOn($other, [
            'scheduled_start' => now()->subMinutes(5),
            'scheduled_end' => now()->addMinutes(55),
        ]);

        $this->postJson($this->url('start', $other), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('source.slug', 'second-stage')
            ->assertJsonPath('live_show.id', $show->id);

        $this->assertSame('live', $show->fresh()->status);
        $this->assertFalse($this->source->shows()->where('status', 'live')->exists());
    }

    public function test_status_reports_the_source_and_what_play_would_do(): void
    {
        $next = $this->showOn($this->source, [
            'title' => 'Opening Ceremony',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->getJson($this->url('status'), $this->headers())
            ->assertOk()
            ->assertJsonPath('source.slug', 'main-stage')
            ->assertJsonPath('live', false)
            ->assertJsonPath('live_show', null)
            ->assertJsonPath('next_show.id', $next->id)
            ->assertJsonPath('next_action', 'start_next');
    }

    public function test_start_goes_live_with_the_show_whose_slot_is_running(): void
    {
        $earlier = $this->showOn($this->source, [
            'title' => 'Now',
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addMinutes(50),
        ]);
        $later = $this->showOn($this->source, [
            'title' => 'Later',
            'scheduled_start' => now()->addHours(2),
            'scheduled_end' => now()->addHours(3),
        ]);

        $this->postJson($this->url('start'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'started_current')
            ->assertJsonPath('live', true)
            ->assertJsonPath('live_show.id', $earlier->id)
            ->assertJsonPath('next_show.id', $later->id)
            ->assertJsonPath('next_action', 'none');

        $this->assertSame('live', $earlier->fresh()->status);
        $this->assertNotNull($earlier->fresh()->actual_start);
        Event::assertDispatched(ShowWentLive::class);
    }

    public function test_start_falls_through_to_the_next_show_when_no_slot_is_running(): void
    {
        $next = $this->showOn($this->source, [
            'scheduled_start' => now()->addMinutes(30),
            'scheduled_end' => now()->addMinutes(90),
        ]);

        $this->postJson($this->url('start'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'started_next')
            ->assertJsonPath('live_show.id', $next->id);

        $this->assertSame('live', $next->fresh()->status);
    }

    /**
     * A slot that has already ended was missed. Starting it would put the wrong title on
     * air, so Play moves past it.
     */
    public function test_start_skips_a_scheduled_show_whose_slot_has_passed(): void
    {
        $missed = $this->showOn($this->source, [
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHours(2),
        ]);
        $next = $this->showOn($this->source, [
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->postJson($this->url('start'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('live_show.id', $next->id);

        $this->assertSame('scheduled', $missed->fresh()->status);
    }

    public function test_start_is_a_no_op_while_a_show_is_live(): void
    {
        $live = $this->showOn($this->source, [
            'status' => 'live',
            'actual_start' => now()->subMinutes(5),
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addMinutes(50),
        ]);
        $queued = $this->showOn($this->source, [
            'scheduled_start' => now()->addHours(2),
            'scheduled_end' => now()->addHours(3),
        ]);

        $this->postJson($this->url('start'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'none')
            ->assertJsonPath('live_show.id', $live->id);

        $this->assertSame('scheduled', $queued->fresh()->status);
    }

    /**
     * A hardware button gets double-tapped. One press takes the show live; the second must
     * not repeat the notification every viewer already received.
     */
    public function test_a_double_press_only_takes_a_show_live_once(): void
    {
        $show = $this->showOn($this->source, [
            'scheduled_start' => now()->subMinutes(5),
            'scheduled_end' => now()->addMinutes(55),
        ]);

        $this->postJson($this->url('start'), [], $this->headers())->assertOk();
        $this->postJson($this->url('start'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'none');

        $this->assertSame('live', $show->fresh()->status);
        Event::assertDispatchedTimes(ShowWentLive::class, 1);
    }

    public function test_a_double_stop_only_ends_a_show_once(): void
    {
        $this->showOn($this->source, [
            'status' => 'live',
            'actual_start' => now()->subMinutes(5),
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addMinutes(50),
        ]);

        $this->postJson($this->url('stop'), [], $this->headers())->assertOk();
        $this->postJson($this->url('stop'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'none');

        Event::assertDispatchedTimes(ShowEnded::class, 1);
    }

    public function test_start_with_nothing_queued_reports_a_conflict(): void
    {
        $this->postJson($this->url('start'), [], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('live', false);
    }

    public function test_start_ignores_shows_on_another_source(): void
    {
        $other = Source::create([
            'name' => 'Second Stage',
            'slug' => 'second-stage',
            'stream_key' => 'other_stream_key',
            'status' => SourceStatusEnum::ONLINE,
        ]);
        $foreign = $this->showOn($other, [
            'scheduled_start' => now()->subMinutes(5),
            'scheduled_end' => now()->addMinutes(55),
        ]);

        $this->postJson($this->url('start'), [], $this->headers())->assertStatus(409);

        $this->assertSame('scheduled', $foreign->fresh()->status);
    }

    public function test_stop_ends_the_live_show(): void
    {
        $live = $this->showOn($this->source, [
            'status' => 'live',
            'actual_start' => now()->subMinutes(20),
            'scheduled_start' => now()->subMinutes(30),
            'scheduled_end' => now()->addMinutes(30),
        ]);

        $this->postJson($this->url('stop'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'stopped')
            ->assertJsonPath('live', false);

        $this->assertSame('ended', $live->fresh()->status);
        $this->assertNotNull($live->fresh()->actual_end);
        Event::assertDispatched(ShowEnded::class);
    }

    public function test_stop_with_nothing_live_is_not_an_error(): void
    {
        $this->postJson($this->url('stop'), [], $this->headers())
            ->assertOk()
            ->assertJsonPath('action', 'none')
            ->assertJsonPath('ok', true);
    }

    /**
     * The surface may sit on a box running UTC, so the clock strings it prints have to be
     * formatted here, in the event's timezone.
     */
    public function test_clock_times_are_preformatted_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        date_default_timezone_set('Europe/Berlin');

        $this->showOn($this->source, [
            'status' => 'live',
            'actual_start' => now()->setTime(14, 5),
            'scheduled_start' => now()->setTime(14, 0),
            'scheduled_end' => now()->setTime(16, 0),
        ]);
        $this->showOn($this->source, [
            'scheduled_start' => now()->addDay()->setTime(17, 30),
            'scheduled_end' => now()->addDay()->setTime(18, 30),
        ]);

        $this->getJson($this->url('status'), $this->headers())
            ->assertOk()
            ->assertJsonPath('live_show.actual_start_clock', '14:05')
            ->assertJsonPath('live_show.scheduled_end_clock', '16:00')
            ->assertJsonPath('next_show.scheduled_start_clock', '17:30');
    }

    public function test_the_key_can_be_passed_as_a_query_parameter(): void
    {
        $this->getJson($this->url('status').'?token='.self::KEY)->assertOk();
    }

    /**
     * A programme title is written for a schedule page. The button gets a version that
     * fits on a key, cut on a word boundary, while the full title stays in the payload.
     */
    public function test_long_titles_are_also_sent_in_a_button_sized_form(): void
    {
        $this->showOn($this->source, [
            'title' => 'Panel: The Art of Fursuit Construction, Part Two',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->getJson($this->url('status'), $this->headers())
            ->assertOk()
            ->assertJsonPath('next_show.title', 'Panel: The Art of Fursuit Construction, Part Two')
            ->assertJsonPath('next_show.title_short', 'Panel: The Art of Fursuit…');
    }

    public function test_a_title_that_already_fits_is_left_alone(): void
    {
        $this->showOn($this->source, [
            'title' => 'Closing Ceremony',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->getJson($this->url('status'), $this->headers())
            ->assertOk()
            ->assertJsonPath('next_show.title_short', 'Closing Ceremony');
    }

    public function test_a_single_long_word_is_cut_rather_than_dropped(): void
    {
        $this->showOn($this->source, [
            'title' => 'Unterhaltungsveranstaltungsprogramm',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->getJson($this->url('status'), $this->headers())
            ->assertOk()
            ->assertJsonPath('next_show.title_short', 'Unterhaltungsveranstaltung…');
    }
}
