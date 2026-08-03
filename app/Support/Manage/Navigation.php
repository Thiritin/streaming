<?php

namespace App\Support\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\SourceStatusEnum;
use App\Models\Emote;
use App\Models\Role;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
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
                $this->item('Shows', 'play-circle', 'manage.shows.index', $badges['shows'] ?? null),
                $this->item('Planner', 'calendar', 'manage.shows.planner'),
                // Import has no rail entry on purpose: it is reached from the Shows
                // table, next to the programme it adds to.
                $this->item('Recordings', 'film', 'manage.recordings.index'),
            ]],
            ['label' => 'Infrastructure', 'items' => [
                $this->item('Servers', 'server', 'manage.servers.index'),
            ]],
            ['label' => 'Administration', 'items' => [
                $this->item('Users', 'users', 'manage.users.index'),
                $this->item('Roles', 'shield-check', 'manage.roles.index', $badges['roles'] ?? null),
                $this->item('Emotes', 'smile', 'manage.emotes.index', $badges['emotes'] ?? null),
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
                'emotes' => $pending > 0 ? ['label' => (string) $pending, 'tone' => Status::WARN] : null,
                'roles' => $roles > 0 ? ['label' => (string) $roles, 'tone' => Status::IDLE] : null,
            ];
        });
    }
}
