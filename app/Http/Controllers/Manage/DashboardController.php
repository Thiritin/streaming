<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\Manage\Overview;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The one screen a stream maintainer or producer keeps open during an event.
 *
 * Every block is a top-level prop so the page can poll the moving parts (viewers,
 * servers, alerts, schedule) without re-running the whole query set, the same trick
 * the status strip uses.
 */
class DashboardController extends Controller
{
    /** Hours of programme the schedule block looks ahead. */
    private const SCHEDULE_HOURS = 6;

    public function __invoke(Request $request, Overview $overview): Response
    {
        return inertia('Manage/Dashboard', [
            'capacity' => fn () => $overview->capacityCards(),
            'edgeServers' => fn () => $overview->edgeServerCards(),
            'viewers' => fn () => $overview->viewers(),
            'servers' => fn () => $overview->servers(),
            'alerts' => fn () => $overview->alerts(),
            'schedule' => fn () => $overview->schedule(self::SCHEDULE_HOURS),
            'scheduleHours' => self::SCHEDULE_HOURS,
        ]);
    }
}
