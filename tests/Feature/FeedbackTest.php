<?php

namespace Tests\Feature;

use App\Models\FeedbackReport;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The reports worth having most are the ones from viewers who never signed
        // in, so the guest path is the one under test here. An installation with
        // mandatory login never reaches it - `auth.optional` answers first.
        config(['auth.required' => false]);
    }

    public function test_a_guest_can_send_feedback(): void
    {
        $this->post(route('feedback.store'), [
            'type' => 'feedback',
            'message' => 'The schedule page is great.',
        ])->assertRedirect();

        $report = FeedbackReport::sole();

        $this->assertSame('feedback', $report->type);
        $this->assertSame('new', $report->status);
        $this->assertNull($report->user_id);
        $this->assertNull($report->telegram);
    }

    public function test_a_stream_report_keeps_the_show_and_its_source(): void
    {
        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id]);

        $this->post(route('feedback.store'), [
            'type' => 'issue',
            'message' => 'Video stalls every few seconds.',
            'show_slug' => $show->slug,
        ])->assertRedirect();

        $report = FeedbackReport::sole();

        $this->assertSame('issue', $report->type);
        $this->assertSame($show->id, $report->show_id);
        $this->assertSame($source->id, $report->source_id);
    }

    public function test_a_signed_in_report_carries_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('feedback.store'), [
                'type' => 'issue',
                'message' => 'No audio on the main stage.',
            ])
            ->assertRedirect();

        $this->assertSame($user->id, FeedbackReport::sole()->user_id);
    }

    public function test_a_telegram_handle_is_stored_bare(): void
    {
        $typed = ['some_handle', '@some_handle', 'https://t.me/some_handle', '  @some_handle '];

        foreach ($typed as $handle) {
            FeedbackReport::query()->delete();

            $this->post(route('feedback.store'), [
                'type' => 'feedback',
                'message' => 'Ask me about this.',
                'telegram' => $handle,
            ])->assertRedirect();

            $report = FeedbackReport::sole();

            $this->assertSame('some_handle', $report->telegram, "typed as '{$handle}'");
            $this->assertSame('@some_handle', $report->telegramHandle());
            $this->assertSame('https://t.me/some_handle', $report->telegramUrl());
        }
    }

    public function test_a_handle_that_is_not_a_handle_is_rejected(): void
    {
        $this->post(route('feedback.store'), [
            'type' => 'feedback',
            'message' => 'Contact me.',
            'telegram' => 'not a handle!',
        ])->assertSessionHasErrors('telegram');

        $this->assertSame(0, FeedbackReport::count());
    }

    public function test_the_message_is_required(): void
    {
        $this->post(route('feedback.store'), ['type' => 'feedback', 'message' => ''])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, FeedbackReport::count());
    }

    public function test_diagnostics_are_stored_and_bounded(): void
    {
        $this->withHeader('User-Agent', 'PHPUnit browser')
            ->post(route('feedback.store'), [
                'type' => 'issue',
                'message' => 'Buffering.',
                'diagnostics' => [
                    'browser' => ['name' => 'Firefox', 'version' => '140.0'],
                    'playback' => ['bufferSeconds' => 0.4, 'droppedFrames' => 812, 'paused' => false],
                    // Past the depth cap, and a value past the length cap.
                    'deep' => ['a' => ['b' => ['c' => ['d' => 'too far']]]],
                    'long' => ['value' => str_repeat('x', 900)],
                ],
            ])
            ->assertRedirect();

        $report = FeedbackReport::sole();

        $this->assertSame('Firefox', $report->diagnostics['browser']['name']);
        $this->assertSame(812, $report->diagnostics['playback']['droppedFrames']);
        $this->assertFalse($report->diagnostics['playback']['paused']);
        // Past the cap the whole branch collapses: the empty parents it leaves behind
        // are dropped rather than stored as husks.
        $this->assertArrayNotHasKey('deep', $report->diagnostics);
        $this->assertSame(500, mb_strlen($report->diagnostics['long']['value']));

        // The header, not the payload: the client's own claim is not the one stored.
        $this->assertSame('PHPUnit browser', $report->user_agent);
    }

    public function test_reports_are_throttled(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->post(route('feedback.store'), [
                'type' => 'feedback',
                'message' => 'Message '.$attempt,
            ])->assertRedirect();
        }

        $this->post(route('feedback.store'), ['type' => 'feedback', 'message' => 'One too many'])
            ->assertStatus(429);

        $this->assertSame(10, FeedbackReport::count());
    }
}
