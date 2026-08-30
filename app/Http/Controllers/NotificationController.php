<?php

namespace App\Http\Controllers;

use App\Models\Show;
use App\Models\User;
use App\Support\NotificationCategories;
use App\Support\NotificationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A viewer's own subscriptions: what they want to hear about, and where.
 *
 * Everything here answers a redirect back rather than JSON. The bell in the archive
 * and the follow button on the guide are Inertia visits like any other control on the
 * page, so the state they change comes back in the props of the page they were pressed
 * on.
 */
class NotificationController extends Controller
{
    /**
     * The categories and transports, saved from /settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $scopes = implode(',', NotificationScope::all());

        // Each card on the settings page saves itself, so a request carries the part it
        // owns and nothing else. Absent means untouched, not emptied.
        $data = $request->validate([
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'in:'.$scopes],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string', 'in:mail,telegram'],
        ]);

        $user = $request->user();
        $attributes = [];

        // Only transports this viewer actually has. A stale tab must not be able to
        // save "telegram" for an account that has since disconnected it, which would
        // read back as a channel that silently never delivers.
        if (array_key_exists('channels', $data)) {
            $attributes['notification_channels'] = array_values(array_intersect(
                $user->availableNotificationChannels(),
                $data['channels'],
            ));
        }

        foreach ($data['scopes'] ?? [] as $category => $scope) {
            $column = NotificationCategories::column((string) $category);

            // Anything not in the registry is dropped rather than failing the save: a
            // stale tab must not be able to write a category that no longer exists.
            if ($column !== null) {
                $attributes[$column] = $scope;
            }
        }

        $user->forceFill($attributes)->save();

        return back()->with('status', 'Saved.');
    }

    /**
     * Follow a show, or stop. Both from the guide.
     */
    public function subscribeToShow(Request $request, Show $show): RedirectResponse
    {
        abort_unless($show->canBeAccessedBy($request->user()), 404);

        $user = $request->user();

        $user->showSubscriptions()->firstOrCreate(['show_id' => $show->id]);

        return back(fallback: route('schedule.index'))
            ->with('toast', $this->confirm($user, 'Following '.$show->title.'.'));
    }

    public function unsubscribeFromShow(Request $request, Show $show): RedirectResponse
    {
        $request->user()->showSubscriptions()->where('show_id', $show->id)->delete();

        return back(fallback: route('schedule.index'))
            ->with('toast', 'No longer following '.$show->title.'.');
    }

    /**
     * What the toast says after a subscription is made.
     *
     * A viewer who has switched both categories off, or who has no address and no
     * linked chat, has just pressed a bell that will never reach them. Saying so on
     * the spot is the only moment they are looking; the alternative is silence they
     * read as working.
     */
    private function confirm(User $user, string $done): string
    {
        if ($user->availableNotificationChannels() === []) {
            return $done.' Add an email address or connect Telegram in Settings to be sent it.';
        }

        $off = $user->notificationScope(NotificationCategories::SHOWS_LIVE) === NotificationScope::OFF
            && $user->notificationScope(NotificationCategories::RECORDINGS) === NotificationScope::OFF;

        return $off
            ? $done.' Notifications are switched off in Settings.'
            : $done;
    }

    public function unlinkTelegram(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ])->save();

        // The transports are stored as a list, so an account that had picked Telegram
        // alone would otherwise be left subscribed to nothing with no sign of it.
        $channels = $user->notification_channels;

        if (is_array($channels)) {
            $user->forceFill([
                'notification_channels' => array_values(array_diff($channels, ['telegram'])),
            ])->save();
        }

        return back()->with('status', 'Disconnected.');
    }
}
