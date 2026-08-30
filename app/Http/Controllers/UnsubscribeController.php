<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MailBranding;
use App\Support\NotificationCategories;
use App\Support\NotificationScope;
use App\Support\UnsubscribeLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The link in the footer of every email.
 *
 * Signed, not authenticated: mail is read on a phone that is not signed in, and an
 * unsubscribe link that first asks for a login is one that does not work. It is also
 * deliberately not behind the notifications feature switch - a promise printed in a
 * message already sent has to keep being true whatever the installation turns off
 * afterwards.
 *
 * Two steps, and the first one changes nothing. Corporate mail scanners and inbox
 * previewers fetch every URL in a message; a link that unsubscribed on GET would
 * unsubscribe people who never opened it.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, User $user, string $category): View
    {
        $this->assertKnown($category);

        return view('emails.unsubscribed', [
            'brand' => MailBranding::all(),
            'title' => $this->title($category),
            'body' => $this->prompt($user, $category),
            'confirmUrl' => $request->fullUrl(),
            'confirmLabel' => $category === UnsubscribeLinks::ALL
                ? 'Unsubscribe from everything'
                : 'Stop these emails',
        ]);
    }

    public function store(Request $request, User $user, string $category): View
    {
        $this->assertKnown($category);

        if ($category === UnsubscribeLinks::ALL) {
            $user->unsubscribeFromEverything();
        } else {
            // Off, not narrowed to the followed shows: somebody who pressed unsubscribe
            // in a message asked for that kind of message to stop, not for less of it.
            $user->forceFill([
                NotificationCategories::column($category) => NotificationScope::OFF,
            ])->save();
        }

        return view('emails.unsubscribed', [
            'brand' => MailBranding::all(),
            'title' => 'Done',
            'body' => $this->confirmation($category),
            'confirmUrl' => null,
            'confirmLabel' => null,
        ]);
    }

    private function assertKnown(string $category): void
    {
        abort_unless(
            $category === UnsubscribeLinks::ALL || in_array($category, NotificationCategories::keys(), true),
            404,
        );
    }

    private function title(string $category): string
    {
        return $category === UnsubscribeLinks::ALL
            ? 'Unsubscribe from everything?'
            : 'Stop '.mb_strtolower(NotificationCategories::label($category)).'?';
    }

    private function prompt(User $user, string $category): string
    {
        return match ($category) {
            UnsubscribeLinks::ALL => "Every notification for {$user->name} will stop.",
            NotificationCategories::SHOWS_LIVE => "{$user->name} will no longer be told when a show goes on air.",
            default => "{$user->name} will no longer be told when a recording is published.",
        };
    }

    private function confirmation(string $category): string
    {
        return match ($category) {
            UnsubscribeLinks::ALL => 'Unsubscribed from everything.',
            NotificationCategories::SHOWS_LIVE => 'Unsubscribed from shows going live.',
            default => 'Unsubscribed from new recordings.',
        };
    }
}
