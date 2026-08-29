<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Response;

/**
 * Attendee records.
 *
 * An account either arrives through the identity provider or is created here. What
 * the provider owns - `sub`, `name` and `reg_id` - stays read-only however the account
 * was made, and a local account simply has no subject. An edge is not shown
 * here either: it belongs to a viewing session, not to an account, and lives on
 * `source_users`.
 *
 * The exception is an account this installation holds itself: a name, an address and a
 * password, created here and given a password here. Both are held to `admin.access`
 * rather than `user.manage`, the same bar as the pane that switches the sign-in modes,
 * because either one hands somebody a way in.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $table = Table::make(User::query()->with('roles'))
            ->name('users')
            ->columns([
                Column::text('name', 'Name')->searchable()->sortable(),
                Column::copyable('sub', 'Subject')->searchable()->toggleable(hiddenByDefault: true),
                Column::number('reg_id', 'Reg ID')->searchable()->sortable(),
                Column::text('roles', 'Roles'),
                Column::datetime('created_at', 'First seen')->sortable()->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::select('role', 'Role')
                    ->options(Role::orderByDesc('priority')->pluck('name', 'slug')->all())
                    ->placeholder('All roles')
                    ->apply(fn ($query, $value) => $query->whereHas(
                        'roles',
                        fn ($roles) => $roles->where('slug', $value),
                    )),
            ])
            ->defaultSort('name', 'asc')
            ->rows(fn (User $user) => $this->row($user))
            ->recordUrl(fn (User $user) => route('manage.users.edit', $user))
            ->rowActions(fn (User $user) => $this->rowActions($user))
            ->pageActions($this->pageActions());

        return inertia('Manage/Users/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return inertia('Manage/Users/Form', [
            'user' => null,
            'options' => [
                'roles' => $this->roleOptions(),
            ],
            'defaults' => [
                'name' => '',
                'email' => '',
                'roles' => [],
            ],
        ]);
    }

    /**
     * Address uniqueness is scoped to accounts that hold a password, as registration
     * scopes it: the identity provider rewrites `users.email` from its claim at every
     * sign-in, so two provider accounts may share one.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class, 'email')->whereNotNull('password'),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        $user = User::create([
            // No identity provider behind this one.
            'sub' => null,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            // An administrator typing the address in is the confirmation. Only
            // self-registration has to prove the address belongs to whoever used it.
            'email_verified_at' => now(),
        ]);

        // The baseline role follows the confirmation wherever it is made, through
        // App\Listeners\AssignBaselineRole.
        event(new Verified($user));

        // Attached, not synced: the baseline role is what every account gets, whatever
        // else was ticked here.
        $user->roles()->syncWithoutDetaching(
            Role::whereIn('slug', $validated['roles'] ?? [])->pluck('id'),
        );

        Toast::flashSuccess('Account created', "'{$user->name}' can sign in with a password.");

        return to_route('manage.users.edit', $user);
    }

    public function edit(User $user): Response
    {
        $this->authorize('view', $user);

        return inertia('Manage/Users/Form', [
            'user' => [
                'id' => $user->id,
                'sub' => $user->sub,
                'name' => $user->name,
                'email' => $user->email,
                'reg_id' => $user->reg_id,
                'roles' => $user->roles->pluck('slug')->all(),
                'has_password' => $user->password !== null,
                'email_verified' => $user->hasVerifiedEmail(),
                'created_at' => $user->created_at?->diffForHumans() ?? '-',
                'updated_at' => $user->updated_at?->diffForHumans() ?? '-',
            ],
            'can' => [
                'password' => request()->user()->can('managePassword', $user),
            ],
            'options' => [
                'roles' => $this->roleOptions(),
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($user),
            ),
            'messages' => $user->messages()
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'message' => $message->message,
                    'created_at' => $message->created_at?->format('M j, Y H:i'),
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        // Sync by slug so the form posts something stable rather than role ids.
        $roleIds = Role::whereIn('slug', $validated['roles'] ?? [])->pluck('id');
        $user->roles()->sync($roleIds);

        Toast::flashSuccess('User updated');

        return back();
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('managePassword', $user);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill(['password' => $validated['password']])->save();

        Toast::flashSuccess('Password set');

        return back();
    }

    /**
     * Clearing a password leaves the identity provider as the way in, so an account
     * that has no provider behind it keeps the one it has.
     */
    public function destroyPassword(User $user): RedirectResponse
    {
        $this->authorize('managePassword', $user);

        if ($user->sub === null) {
            Toast::flashDanger('Cannot clear password', 'This account has no other way to sign in.');

            return back();
        }

        $user->forceFill(['password' => null])->save();

        Toast::flashSuccess('Password cleared');

        return back();
    }

    public function destroy(User $user): RedirectResponse
    {
        if (! request()->user()->can('delete', $user)) {
            Toast::flashDanger('Cannot delete user', 'You cannot delete your own account.');

            return back();
        }

        $name = $user->name;
        $user->delete();

        Toast::flashSuccess('User deleted', "'{$name}' has been removed.");

        return to_route('manage.users.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        $roles = $user->roles->sortByDesc('priority');

        return [
            'name' => $user->name,
            // Null, not '', for the same reason as reg_id below: the cell renders its
            // own placeholder for an empty value, and a local account has no subject.
            'sub' => $user->sub ?: null,
            // Null, not '-': this is a number column, and it renders its own
            // placeholder for an empty cell.
            'reg_id' => $user->reg_id,
            'roles' => $roles->isEmpty() ? '-' : $roles->pluck('name')->implode(', '),
            'created_at' => $user->created_at?->format('M j, Y H:i'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(User $user): array
    {
        $actions = [
            Action::link('edit', 'Edit', route('manage.users.edit', $user))->icon('pencil'),
        ];

        if (request()->user()->can('update', $user)) {
            $actions[] = $this->deleteAction($user);
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function recordActions(User $user): array
    {
        $actions = [];

        if (! $user->hasVerifiedEmail() && request()->user()->can('verifyEmail', $user)) {
            $actions[] = Action::post('verify', 'Confirm address', route('manage.users.verify', $user))
                ->icon('check');
        }

        if ($user->password !== null && request()->user()->can('managePassword', $user)) {
            $actions[] = Action::delete('clear-password', 'Clear password', route('manage.users.password.destroy', $user))
                ->icon('key')
                ->tone(Status::WARN)
                ->disabled($user->sub === null ? 'This account has no other way to sign in.' : null)
                ->confirm(
                    'Clear password',
                    "'{$user->name}' will sign in through the identity provider only.",
                    'Clear',
                );
        }

        if (request()->user()->can('update', $user)) {
            $actions[] = $this->deleteAction($user);
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', User::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Account', route('manage.users.create'))->icon('plus'),
        ];
    }

    private function deleteAction(User $user): Action
    {
        $self = request()->user()->id === $user->id;

        return Action::delete('delete', 'Delete', route('manage.users.destroy', $user))
            ->icon('trash-2')
            ->tone(Status::DANGER)
            ->disabled($self ? 'You cannot delete your own account.' : null)
            ->confirm(
                'Delete user',
                "Deleting '{$user->name}' also removes their chat history and watch records.",
                'Delete',
            );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return Role::orderByDesc('priority')
            ->get()
            ->map(fn (Role $role) => ['value' => $role->slug, 'label' => $role->name])
            ->all();
    }
}
