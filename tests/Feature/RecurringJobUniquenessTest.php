<?php

namespace Tests\Feature;

use App\Jobs\CleanupStaleViewerSessionsJob;
use App\Jobs\SaveViewCountJob;
use App\Jobs\Server\ServerHealthCheckJob;
use App\Jobs\ServerAssignmentJob;
use App\Jobs\UpdateListenerCountJob;
use App\Jobs\UpdateServerViewerCountsJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduler dispatches these on a fixed interval regardless of whether the last
 * copy ever ran, so a queue that stops draining accumulates them without limit. When
 * Horizon's workers could not start, five hours produced 8,200 queued jobs - almost
 * entirely these six classes, in exactly their scheduled ratios.
 *
 * None of them is worth running twice: each recomputes current state rather than
 * accumulating it, so a copy already sitting in the queue does everything a second
 * one would. `ShouldBeUnique` is what caps the standing backlog at one per class.
 *
 * Pinned as a test because the failure is invisible until something else breaks: with
 * a healthy queue, a missing `ShouldBeUnique` looks exactly like a working one.
 */
class RecurringJobUniquenessTest extends TestCase
{
    /** Every job the scheduler dispatches on an interval. */
    public static function recurringJobs(): array
    {
        return [
            'server assignment (15s)' => [ServerAssignmentJob::class],
            'server viewer counts (30s)' => [UpdateServerViewerCountsJob::class],
            'listener count (1m)' => [UpdateListenerCountJob::class],
            'view count (1m)' => [SaveViewCountJob::class],
            'stale viewer sessions (1m)' => [CleanupStaleViewerSessionsJob::class],
            'server health check (1m)' => [ServerHealthCheckJob::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recurringJobs')]
    public function test_a_recurring_job_declares_itself_unique(string $job): void
    {
        $this->assertInstanceOf(
            ShouldBeUnique::class,
            new $job,
            "{$job} is dispatched on a schedule, so without ShouldBeUnique it stacks up "
            .'once the queue stops draining.',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recurringJobs')]
    public function test_the_uniqueness_lock_expires(string $job): void
    {
        // A job that dies without releasing its lock would otherwise never be
        // dispatched again. uniqueFor bounds that outage.
        $this->assertGreaterThan(
            0,
            (new $job)->uniqueFor ?? 0,
            "{$job} must set uniqueFor, or a crashed run blocks it permanently.",
        );
    }

    /**
     * The behaviour, not just the interface: a second dispatch while one is pending
     * must be dropped.
     */
    public function test_a_second_dispatch_is_dropped_while_one_is_pending(): void
    {
        Queue::fake();

        ServerAssignmentJob::dispatch();
        ServerAssignmentJob::dispatch();
        ServerAssignmentJob::dispatch();

        Queue::assertPushed(ServerAssignmentJob::class, 1);
    }
}
