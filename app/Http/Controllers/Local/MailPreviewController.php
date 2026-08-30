<?php

namespace App\Http\Controllers\Local;

use App\Http\Controllers\Controller;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Notifications\RecordingPublished;
use App\Notifications\ShowStarted;
use App\Support\MailBranding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The notification emails, in a browser.
 *
 * Local only, like everything else in routes/local.php. It renders the same view with
 * the same data the mailer would, taken off the MailMessage the notification builds,
 * so a preview cannot drift from what is actually sent - there is no second copy of
 * the payload here to fall out of step.
 *
 * Real rows when the database has them, and a plausible stand-in when it does not: a
 * fresh install has no archive, and a template is worth looking at before there is
 * anything to send.
 */
class MailPreviewController extends Controller
{
    public function index(): View
    {
        return view('local.mail-index', [
            'brand' => MailBranding::all(),
            'templates' => [
                ['key' => 'recording-published', 'label' => 'New recording (archive subscription)'],
                ['key' => 'recording-followed', 'label' => 'New recording (show followed)'],
                ['key' => 'show-live', 'label' => 'A followed show has started'],
                ['key' => 'unsubscribe', 'label' => 'Unsubscribe confirmation page'],
            ],
        ]);
    }

    public function show(Request $request, string $template): View
    {
        $user = $this->viewer();

        return match ($template) {
            'recording-published' => $this->render(
                (new RecordingPublished($this->sampleRecording(), ['mail'], followed: false))->toMail($user),
            ),
            'recording-followed' => $this->render(
                (new RecordingPublished($this->sampleRecording(), ['mail'], followed: true))->toMail($user),
            ),
            'show-live' => $this->render(
                (new ShowStarted($this->sampleShow(), ['mail'], followed: true))->toMail($user),
            ),
            'unsubscribe' => view('emails.unsubscribed', [
                'brand' => MailBranding::all(),
                'title' => 'Stop new recordings?',
                'body' => "{$user->name} will no longer be told about new recordings.",
                'confirmUrl' => '#',
                'confirmLabel' => 'Stop these emails',
            ]),
            default => abort(404),
        };
    }

    /**
     * The MailMessage carries the view and the data it was built with, so the preview
     * renders exactly what the mailer would rather than a second assembly of it.
     */
    private function render(MailMessage $message): View
    {
        return view($message->view, $message->viewData);
    }

    private function viewer(): User
    {
        return User::first() ?? new User([
            'id' => 0,
            'name' => 'Alex Rivers',
            'email' => 'alex@example.org',
        ]);
    }

    private function sampleRecording(): Recording
    {
        $recording = Recording::with(['source', 'show', 'category'])
            ->where('is_published', true)
            ->latest('date')
            ->first();

        if ($recording) {
            return $recording;
        }

        $recording = new Recording([
            'title' => 'Closing Ceremony',
            'description' => 'The last hour of the run: the thank-yous, the numbers, and the announcement of where everybody is going next year.',
            'date' => now(),
            'duration' => 4230,
        ]);

        $recording->id = 0;
        $recording->setRelation('source', new Source(['name' => 'Main Stage']));

        return $recording;
    }

    private function sampleShow(): Show
    {
        $show = Show::with('source')->latest('scheduled_start')->first();

        if ($show) {
            return $show;
        }

        $show = new Show([
            'title' => 'Dance Competition Finals',
            'slug' => 'dance-competition-finals',
            'description' => 'Six finalists, one stage, and the loudest room of the weekend.',
        ]);

        $show->setRelation('source', new Source(['name' => 'Main Stage']));

        return $show;
    }
}
