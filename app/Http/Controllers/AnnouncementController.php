<?php

namespace App\Http\Controllers;

use App\Support\Announcement;
use Inertia\Response;

/**
 * The announcement in full, where the banner's "read more" points. 404s until an
 * announcement is up and more than a banner's worth has been written.
 */
class AnnouncementController extends Controller
{
    public function show(): Response
    {
        $announcement = Announcement::page();

        abort_if($announcement === null, 404);

        return inertia('Announcement', [
            'announcementPage' => $announcement,
        ]);
    }
}
