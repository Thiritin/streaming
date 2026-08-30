<?php

namespace App\Support\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\SourceStatusEnum;
use App\Models\Emote;
use App\Models\Event;
use App\Models\FeedbackReport;
use App\Models\RecordingComment;
use App\Models\Role;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * The rail structure, shared on every /manage response.
 *
 * Items whose route does not exist yet are dropped, so each rebuild phase can add a
 * module without touching this file. Badge counts are cached briefly because the
 * status strip polls.
 */
final class Navigation
{
    private const BADGE_TTL = 5;

    /**
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public function groups(): array
    {
        $badges = $this->badges();

        /*
         * Four headings, deliberately. Seven groups meant most of them held one
         * item, so the rail was mostly separators and the eye had to read every
         * heading to find anything. Programme work is one block, the machines are
         * another, and everything that is configuration rather than daily
         * operation sits under Administration.
         */
        $groups = [
            ['label' => 'Overview', 'items' => [
                $this->item('Dashboard', 'layout-dashboard', 'manage.home', $badges['alerts'] ?? null),
            ]],
            ['label' => 'Streaming', 'items' => [
                $this->item('Sources', 'video', 'manage.sources.index', $badges['sources'] ?? null),
                // Preview has no rail entry: it is a mode of the Sources list, reached
                // from a button on it, the same way the planner is reached from Shows.
                $this->item('Shows', 'play-circle', 'manage.shows.index', $badges['shows'] ?? null),
                // Import has no rail entry on purpose: it is reached from the Shows
                // table, next to the programme it adds to.
                $this->item('Recordings', 'film', 'manage.recordings.index'),
                $this->item('Recording Plan', 'clapperboard', 'manage.recordings.plan', $badges['recording_gaps'] ?? null),
            ]],
            ['label' => 'Infrastructure', 'items' => [
                $this->item('Servers', 'server', 'manage.servers.index'),
                Features::screens() ? $this->item('Display Keys', 'monitor', 'manage.embed-keys.index') : null,
                Features::screens() ? $this->item('Screens', 'monitor-play', 'manage.displays.index') : null,
            ]],
            ['label' => 'Administration', 'items' => [
                Features::feedback()
                    ? $this->item('Feedback', 'message-square', 'manage.feedback.index', $badges['feedback'] ?? null)
                    : null,
                Features::comments()
                    ? $this->item('Comments', 'messages-square', 'manage.comments.index', $badges['comments'] ?? null)
                    : null,
                // Administrators only, like the settings pane the bot's token lives in:
                // an interactive chat can start and end shows.
                Features::telegram() && request()->user()?->hasPermission('admin.access')
                    ? $this->item('Telegram', 'send', 'manage.telegram.index')
                    : null,
                $this->item('Users', 'users', 'manage.users.index'),
                $this->item('Roles', 'shield-check', 'manage.roles.index', $badges['roles'] ?? null),
                Features::emotes()
                    ? $this->item('Emotes', 'smile', 'manage.emotes.index', $badges['emotes'] ?? null)
                    : null,
                $this->item('Settings', 'paintbrush', 'manage.settings'),
            ]],
        ];

        return collect($groups)
            ->map(fn (array $group) => [
                'label' => $group['label'],
                'items' => array_values(array_filter($group['items'])),
            ])
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array{label: string, tone: string}|null  $badge
     * @return array<string, mixed>|null
     */
    private function item(string $label, string $icon, string $route, ?array $badge = null): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        return [
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'url' => route($route),
            'badge' => $badge,
        ];
    }

    /**
     * Mirrors the Filament navigation badges: live/upcoming shows, online sources,
     * pending emotes, total roles.
     *
     * @return array<string, array{label: string, tone: string}|null>
     */
    private function badges(): array
    {
        return Cache::remember('manage.nav.badges', self::BADGE_TTL, function () {
            $live = Show::live()->count();
            $upcoming = Show::upcoming()->count();
            $online = Source::where('status', SourceStatusEnum::ONLINE)->count();
            $pending = Emote::pending()->count();
            $unread = FeedbackReport::unread()->count();
            // Comments a report has taken down and nobody has ruled on. They are
            // invisible to the room while they sit here, so the count is the wait.
            $reported = RecordingComment::whereNotNull('hidden_at')->count();

            /*
             * What is outstanding: shows meant to be published that have been on air and
             * are still not out. A show whose material is gone for good is left out -
             * nobody can act on it - and so is one nobody is publishing.
             */
            $outstanding = Show::awaitingPublication()
                // The run the plan page opens on and nothing else. A badge counting shows
                // from three events ago is a number nobody will ever act on. An
                // installation with no calendar counts the lot, as it did before events.
                ->when(Event::mostRecent(), fn ($query, Event $event) => $query->where('event_id', $event->id))
                ->count();
            $roles = Role::count();

            /*
             * A cheap count of the hard failures the dashboard lists, so the rail can
             * flag them without running the full alert set on every request.
             */
            $broken = Source::where('status', SourceStatusEnum::ERROR)->count()
                + Server::whereNot('status', ServerStatusEnum::DELETED)
                    ->where(fn ($query) => $query
                        ->where('status', ServerStatusEnum::ERROR)
                        ->orWhere('health_status', 'unhealthy'))
                    ->count();

            return [
                'alerts' => $broken > 0 ? ['label' => (string) $broken, 'tone' => Status::DANGER] : null,
                'shows' => match (true) {
                    $live > 0 => ['label' => $live.' live', 'tone' => Status::LIVE],
                    $upcoming > 0 => ['label' => (string) $upcoming, 'tone' => Status::WARN],
                    default => null,
                },
                'sources' => $online > 0 ? ['label' => (string) $online, 'tone' => Status::OK] : null,
                'recording_gaps' => $outstanding > 0 ? ['label' => (string) $outstanding, 'tone' => Status::DANGER] : null,
                'emotes' => $pending > 0 ? ['label' => (string) $pending, 'tone' => Status::WARN] : null,
                'feedback' => $unread > 0 ? ['label' => (string) $unread, 'tone' => Status::WARN] : null,
                'comments' => $reported > 0 ? ['label' => (string) $reported, 'tone' => Status::DANGER] : null,
                'roles' => $roles > 0 ? ['label' => (string) $roles, 'tone' => Status::IDLE] : null,
            ];
        });
    }
}
