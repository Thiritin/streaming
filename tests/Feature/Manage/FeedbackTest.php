<?php

namespace Tests\Feature\Manage;

use App\Models\FeedbackReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    private function report(array $attributes = []): FeedbackReport
    {
        return FeedbackReport::create(array_merge([
            'type' => FeedbackReport::TYPE_ISSUE,
            'status' => FeedbackReport::STATUS_NEW,
            'message' => 'Stream keeps stalling.',
            'telegram' => 'some_handle',
            'diagnostics' => ['browser' => ['name' => 'Firefox', 'version' => '140.0']],
        ], $attributes));
    }

    public function test_the_list_defaults_to_what_still_needs_attention(): void
    {
        $open = $this->report();
        $this->report(['status' => FeedbackReport::STATUS_RESOLVED, 'message' => 'Already dealt with.']);

        $this->actingAs($this->admin)
            ->get(route('manage.feedback.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Feedback/Index')
                ->where('table.rows.0.id', $open->id)
                ->has('table.rows', 1));
    }

    public function test_a_report_page_shows_the_diagnostics_that_came_with_it(): void
    {
        $report = $this->report();

        $this->actingAs($this->admin)
            ->get(route('manage.feedback.show', $report))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Feedback/Show')
                ->where('report.telegram', '@some_handle')
                ->where('report.diagnostics.0.group', 'Browser'));
    }

    public function test_resolving_records_who_did_it(): void
    {
        $report = $this->report();

        $this->actingAs($this->admin)
            ->post(route('manage.feedback.status', ['feedback' => $report, 'status' => 'resolved']))
            ->assertRedirect();

        $report->refresh();

        $this->assertSame(FeedbackReport::STATUS_RESOLVED, $report->status);
        $this->assertSame($this->admin->id, $report->handled_by);
        $this->assertNotNull($report->handled_at);
    }

    public function test_a_resolved_report_can_be_reopened(): void
    {
        $report = $this->report(['status' => FeedbackReport::STATUS_RESOLVED]);

        $this->actingAs($this->admin)
            ->post(route('manage.feedback.status', ['feedback' => $report, 'status' => 'open']))
            ->assertRedirect();

        $this->assertSame(FeedbackReport::STATUS_OPEN, $report->fresh()->status);
    }

    public function test_triaging_needs_the_manage_permission(): void
    {
        $report = $this->report();

        $this->actingAs($this->viewer)
            ->post(route('manage.feedback.status', ['feedback' => $report, 'status' => 'resolved']))
            ->assertForbidden();

        $this->assertSame(FeedbackReport::STATUS_NEW, $report->fresh()->status);
    }

    public function test_a_moderator_can_read_reports_without_being_able_to_close_them(): void
    {
        $report = $this->report();

        $this->actingAs($this->moderator)
            ->get(route('manage.feedback.index'))
            ->assertOk();

        $this->actingAs($this->moderator)
            ->post(route('manage.feedback.status', ['feedback' => $report, 'status' => 'resolved']))
            ->assertForbidden();
    }

    public function test_deleting_removes_the_report(): void
    {
        $report = $this->report();

        $this->actingAs($this->admin)
            ->delete(route('manage.feedback.destroy', $report))
            ->assertRedirect(route('manage.feedback.index'));

        $this->assertSame(0, FeedbackReport::count());
    }

    public function test_bulk_resolve_closes_the_selection(): void
    {
        $first = $this->report();
        $second = $this->report(['message' => 'Audio is out.']);

        $this->actingAs($this->admin)
            ->post(route('manage.feedback.bulk.resolve'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect();

        $this->assertSame(0, FeedbackReport::unresolved()->count());
    }
}
