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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Attendee records.
 *
 * There is no create screen: users arrive through OIDC, and `sub`, `name` and
 * `reg_id` are owned by the identity provider. Roles are the only thing an operator
 * can change, so they are the only writable control. An edge is not shown here either:
 * it belongs to a viewing session, not to an account, and lives on `source_users`.
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
            ->rowActions(fn (User $user) => $this->rowActions($user));

        return inertia('Manage/Users/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function edit(User $user): Response
    {
        $this->authorize('view', $user);

        return inertia('Manage/Users/Form', [
            'user' => [
                'id' => $user->id,
                'sub' => $user->sub,
                'name' => $user->name,
                'reg_id' => $user->reg_id,
                'roles' => $user->roles->pluck('slug')->all(),
                'created_at' => $user->created_at?->diffForHumans() ?? '-',
                'updated_at' => $user->updated_at?->diffForHumans() ?? '-',
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
            'sub' => $user->sub,
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
        return request()->user()->can('update', $user) ? [$this->deleteAction($user)] : [];
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
