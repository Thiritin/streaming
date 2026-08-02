<?php

namespace App\Http\Controllers\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Server;
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
 * `reg_id` are owned by the identity provider. What an operator can actually
 * change is the edge server assignment and which roles are attached, so those are
 * the only writable controls.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $table = Table::make(User::query()->with(['server', 'roles']))
            ->name('users')
            ->columns([
                Column::text('name', 'Name')->searchable()->sortable(),
                Column::copyable('sub', 'Subject')->searchable()->toggleable(hiddenByDefault: true),
                Column::number('reg_id', 'Reg ID')->searchable()->sortable(),
                Column::text('roles', 'Roles'),
                Column::text('server', 'Edge server'),
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
                'server_id' => $user->server_id,
                'roles' => $user->roles->pluck('slug')->all(),
                'created_at' => $user->created_at?->diffForHumans() ?? '-',
                'updated_at' => $user->updated_at?->diffForHumans() ?? '-',
            ],
            'options' => [
                'servers' => $this->serverOptions(),
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
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        $user->update(['server_id' => $validated['server_id'] ?? null]);

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
            'reg_id' => $user->reg_id ?? '-',
            'roles' => $roles->isEmpty() ? '-' : $roles->pluck('name')->implode(', '),
            'server' => $user->server?->hostname ?? '-',
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
     * Only active edge servers can take a viewer, which is what the Filament select
     * restricted to as well.
     *
     * @return array<int, array{value: int|string, label: string}>
     */
    private function serverOptions(): array
    {
        $options = [['value' => '', 'label' => 'Not assigned']];

        foreach (Server::query()
            ->where('type', ServerTypeEnum::EDGE)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->orderBy('hostname')
            ->get() as $server) {
            $options[] = ['value' => $server->id, 'label' => $server->hostname];
        }

        return $options;
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
