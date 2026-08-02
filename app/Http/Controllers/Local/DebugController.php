<?php

namespace App\Http\Controllers\Local;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Show;
use App\Models\User;
use App\Services\Chat\MessagePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Local-only account switcher, so chat can be exercised as several people
 * without going through the OIDC provider each time.
 *
 * Every route here is registered only when `app()->isLocal()` and is additionally
 * guarded by the LocalOnly middleware.
 */
class DebugController extends Controller
{
    /**
     * Personas that can be spawned on demand, in the order they are shown.
     */
    private const PERSONAS = [
        'admin' => 'Admin',
        'moderator' => 'Moderator',
        'staff' => 'Staff',
        'supersponsor' => 'Super Sponsor',
        'sponsor' => 'Sponsor',
        'attendee' => 'Attendee',
    ];

    public function index()
    {
        $users = User::with('roles')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'color' => $user->chat_color,
                'badges' => $user->chatBadges(),
                'roles' => $user->roles->pluck('slug')->all(),
                'is_test' => str_starts_with((string) $user->sub, 'debug|'),
            ]);

        $current = Auth::user();

        return Inertia::render('Debug/Users', [
            'users' => $users,
            'current' => $current ? [
                'id' => $current->id,
                'name' => $current->name,
                'badges' => $current->chatBadges(),
            ] : null,
            'personas' => collect(self::PERSONAS)
                ->map(fn (string $label, string $slug) => ['slug' => $slug, 'label' => $label])
                ->values(),
            'shows' => Show::whereNotNull('source_id')
                ->whereIn('status', ['live', 'scheduled'])
                ->orderByDesc('status')
                ->limit(8)
                ->get()
                ->map(fn (Show $show) => [
                    'title' => $show->title,
                    'status' => $show->status,
                    'url' => route('show.view', $show),
                    'chat_url' => route('show.chat', $show),
                ]),
        ]);
    }

    /**
     * Become an existing user.
     */
    public function loginAs(User $user)
    {
        Auth::login($user);
        request()->session()->regenerate();

        return back()->with('status', "Now signed in as {$user->name}.");
    }

    /**
     * Create (or reuse) a throwaway user holding a single role.
     */
    public function persona(Request $request)
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', array_keys(self::PERSONAS))],
        ]);

        $slug = $data['role'];
        $role = Role::where('slug', $slug)->first();

        if (! $role) {
            return back()->with('status', "Role '{$slug}' does not exist in this database.");
        }

        $user = User::create([
            'sub' => 'debug|'.$slug.'|'.Str::lower(Str::random(6)),
            'name' => self::PERSONAS[$slug].' '.random_int(100, 999),
        ]);

        $user->roles()->attach($role->id);

        MessagePresenter::forgetAuthor($user->id);

        Auth::login($user->fresh());
        $request->session()->regenerate();

        return back()->with('status', "Created and signed in as {$user->name}.");
    }

    /**
     * Delete every generated persona. The account in use is signed out first.
     */
    public function reset(Request $request)
    {
        $ids = User::where('sub', 'like', 'debug|%')->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('status', 'No test users to remove.');
        }

        if (in_array(Auth::id(), $ids->all(), true)) {
            Auth::logout();
            $request->session()->regenerate();
        }

        User::whereIn('id', $ids)->delete();

        return back()->with('status', "Removed {$ids->count()} test users.");
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->regenerate();

        return back()->with('status', 'Signed out.');
    }
}
