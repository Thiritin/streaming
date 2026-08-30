<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\Recording;
use App\Models\Show;
use App\Models\ShowSubscription;
use App\Models\User;
use App\Notifications\RecordingPublished;
use App\Notifications\ShowStarted;
use App\Support\Features;
use App\Support\NotificationCategories;
use App\Support\NotificationScope;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who hears about what, and the guarantee that they hear about it once.
 *
 * Two audiences arrive at the same message: the standing bell in the archive, and the
 * shows somebody chose to follow. They are deliberately assembled together rather than
 * mailed separately, because a viewer who pressed both must not be written to twice -
 * and the thing that makes that true is the claim on `notification_deliveries`, which
 * is taken before anything is sent and is unique on viewer, subject and transport.
 *
 * Sends are synchronous here on purpose. This runs inside a queued job already, and a
 * failure has to be recorded against the claim it was made under; queuing each
 * notification again would put the send somewhere the claim cannot see it.
 */
class ViewerNotifier
{
    /**
     * Everyone whose recordings scope covers this one.
     */
    public function recordingPublished(Recording $recording): void
    {
        if (! Features::enabled('notifications')) {
            return;
        }

        $followerIds = $this->followerIds($recording->show_id);

        foreach ($this->audience(NotificationCategories::RECORDINGS, $followerIds) as $user) {
            // A restricted recording is not made less restricted by being announced.
            if (! $recording->canBeAccessedBy($user)) {
                continue;
            }

            $followed = in_array($user->id, $followerIds, true);

            $sent = $this->deliver(
                $user,
                NotificationDelivery::TYPE_RECORDING_PUBLISHED,
                $recording->id,
                fn (array $channels) => new RecordingPublished($recording, $channels, $followed),
            );

            // A follow is a question - "tell me when this is up" - and the recording is
            // the answer. Once it has actually been sent the row has nothing left to do,
            // so it goes rather than sitting in the viewer's list for ever. Only on a
            // real send: somebody with nothing to be reached on must not lose the follow
            // having been told nothing.
            if ($sent && $followed) {
                $user->showSubscriptions()->where('show_id', $recording->show_id)->delete();
            }
        }
    }

    /**
     * Everyone whose live scope covers this show.
     */
    public function showStarted(Show $show): void
    {
        if (! Features::enabled('notifications')) {
            return;
        }

        $followerIds = $this->followerIds($show->id);

        foreach ($this->audience(NotificationCategories::SHOWS_LIVE, $followerIds) as $user) {
            if (! $show->canBeAccessedBy($user)) {
                continue;
            }

            $this->deliver(
                $user,
                NotificationDelivery::TYPE_SHOW_LIVE,
                $show->id,
                fn (array $channels) => new ShowStarted($show, $channels, in_array($user->id, $followerIds, true)),
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function followerIds(?int $showId): array
    {
        if (! $showId) {
            return [];
        }

        return ShowSubscription::where('show_id', $showId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Who this category reaches: everyone who set it to `any`, plus the people who
     * followed this particular show and left it on the default.
     *
     * One query rather than two collections merged, so a convention-sized `any`
     * audience is not loaded twice to be deduplicated afterwards.
     *
     * @param  array<int, int>  $followerIds
     * @return Collection<int, User>
     */
    private function audience(string $category, array $followerIds)
    {
        $column = NotificationCategories::column($category);

        return User::query()
            ->where(fn ($query) => $query
                ->where($column, NotificationScope::ANY)
                ->when($followerIds !== [], fn ($followed) => $followed->orWhere(
                    fn ($inner) => $inner
                        ->where($column, NotificationScope::SUBSCRIBED)
                        ->whereIn('id', $followerIds),
                )))
            ->get();
    }

    /**
     * One viewer, one subject, every transport they asked for - each claimed on its
     * own, so a Telegram account that has blocked the bot cannot cost them the email.
     *
     * Answers whether anything actually went out, which is what decides if a spent
     * subscription can be cleared away.
     *
     * @param  callable(array<int, string>): Notification  $build
     */
    private function deliver(User $user, string $type, int $subjectId, callable $build): bool
    {
        $sent = false;

        foreach ($user->notificationChannels() as $channel) {
            $claim = NotificationDelivery::claim($user->id, $type, $subjectId, $channel);

            // Somebody already sent this. Not an error, and the usual reason it happens
            // is a job retried after a partial failure.
            if (! $claim) {
                continue;
            }

            try {
                $user->notifyNow($build([$channel]), [$channel]);
                $claim->markSent();
                $sent = true;
            } catch (Throwable $e) {
                $claim->markFailed($e->getMessage());

                Log::warning('Viewer notification failed', [
                    'user_id' => $user->id,
                    'type' => $type,
                    'subject_id' => $subjectId,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
