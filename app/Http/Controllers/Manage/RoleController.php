<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\RoleRequest;
use App\Models\Role;
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
 * Roles: the chat badge colour a member gets, and the permission strings the
 * panel itself is gated on.
 *
 * `is_staff` is deliberately absent. The column was dropped from the table in
 * 2025_08_29_171750 and staff is derived from holding the `admin` role, so the
 * toggle the Filament resource still rendered wrote nowhere.
 */
class RoleController extends Controller
{
    /**
     * Permission strings the system actually checks, offered as a checklist so a
     * typo cannot silently grant nothing.
     */
    private const PERMISSIONS = [
        'admin.access' => 'Admin access — full run of /manage',
        'filament.access' => 'Legacy admin access — still honoured on existing rows',
        'stream.manage' => 'Manage sources, shows, servers and recordings',
        'user.manage' => 'Manage users and roles',
        'chat.moderate' => 'Moderate chat',
        'chat.delete' => 'Delete chat messages',
        'chat.timeout' => 'Time users out',
        'chat.slowmode' => 'Toggle slow mode',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $table = Table::make(Role::query()->withCount('users'))
            ->name('roles')
            ->columns([
                Column::text('name', 'Name')->searchable()->sortable(),
                Column::copyable('slug', 'Slug')->searchable()->sortable(),
                Column::color('chat_color', 'Chat colour'),
                Column::number('priority', 'Priority')->sortable(),
                Column::badge('assigned_at_login', 'Login sync'),
                Column::badge('is_visible', 'Chat badge'),
                Column::number('users_count', 'Users')->sortable('users_count'),
                Column::datetime('created_at', 'Created')->sortable()->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::ternary('assigned_at_login', 'Login assignment')
                    ->trueLabel('Login-synced only')
                    ->falseLabel('Manually assigned only')
                    ->placeholder('All roles'),
                Filter::ternary('is_visible', 'Chat visibility')
                    ->trueLabel('Visible only')
                    ->falseLabel('Hidden only')
                    ->placeholder('All roles'),
            ])
            ->defaultSort('priority', 'desc')
            ->rows(fn (Role $role) => $this->row($role))
            ->recordUrl(fn (Role $role) => route('manage.roles.edit', $role))
            ->rowActions(fn (Role $role) => $this->rowActions($role))
            ->pageActions($this->pageActions());

        return inertia('Manage/Roles/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return inertia('Manage/Roles/Form', [
            'role' => null,
            'options' => ['permissions' => $this->permissionOptions()],
            'defaults' => [
                'name' => '',
                'slug' => '',
                'description' => '',
                'chat_color' => '#808080',
                'priority' => 0,
                'is_visible' => true,
                'assigned_at_login' => true,
                'permissions' => [],
            ],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $role = Role::create($request->validated());

        Toast::flashSuccess('Role created', "'{$role->name}' is ready to assign.");

        return to_route('manage.roles.edit', $role);
    }

    public function edit(Role $role): Response
    {
        $this->authorize('view', $role);

        return inertia('Manage/Roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'chat_color' => $role->chat_color,
                'priority' => $role->priority,
                'is_visible' => (bool) $role->is_visible,
                'assigned_at_login' => (bool) $role->assigned_at_login,
                'permissions' => $role->permissions ?? [],
                'users_count' => $role->users()->count(),
            ],
            'options' => ['permissions' => $this->permissionOptions()],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                request()->user()->can('update', $role) ? [$this->deleteAction($role)] : [],
            ),
            'members' => $role->users()
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'url' => route('manage.users.edit', $user),
                ])
                ->all(),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $role->update($request->validated());

        Toast::flashSuccess('Role updated');

        return back();
    }

    public function destroy(Role $role): RedirectResponse
    {
        // RolePolicy refuses while the role still has members.
        if (! request()->user()->can('delete', $role)) {
            Toast::flashDanger('Cannot delete role', 'This role still has members. Remove them first.');

            return back();
        }

        $name = $role->name;
        $role->delete();

        Toast::flashSuccess('Role deleted', "'{$name}' has been removed.");

        return to_route('manage.roles.index');
    }

    /**
     * Bootstrap for a fresh install: without at least one role carrying
     * `admin.access` nobody can reach /manage at all.
     */
    public function seedDefaults(): RedirectResponse
    {
        $this->authorize('create', Role::class);

        if (Role::query()->exists()) {
            Toast::flashDanger('Roles already exist', 'The defaults are only offered on an empty install.');

            return back();
        }

        foreach ($this->defaultRoles() as $role) {
            Role::create($role);
        }

        Toast::flashSuccess('Default roles created');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Role $role): array
    {
        return [
            'name' => $role->name,
            'slug' => $role->slug,
            'chat_color' => $role->chat_color,
            'priority' => $role->priority,
            'assigned_at_login' => $role->assigned_at_login
                ? Status::make('Auto-synced', Status::OK)
                : Status::make('Manual', Status::WARN),
            'is_visible' => $role->is_visible
                ? Status::make('Shown', Status::OK)
                : Status::make('Hidden', Status::IDLE),
            'users_count' => $role->users_count,
            'created_at' => $role->created_at?->format('M j, Y H:i'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Role $role): array
    {
        $actions = [
            Action::link('edit', 'Edit', route('manage.roles.edit', $role))->icon('pencil'),
        ];

        if (request()->user()->can('update', $role)) {
            $actions[] = $this->deleteAction($role);
        }

        return $actions;
    }

    private function deleteAction(Role $role): Action
    {
        $members = $role->users()->count();

        return Action::delete('delete', 'Delete', route('manage.roles.destroy', $role))
            ->icon('trash-2')
            ->tone(Status::DANGER)
            ->disabled($members > 0 ? "This role still has {$members} member(s)." : null)
            ->confirm('Delete role', "'{$role->name}' will no longer grant anything.", 'Delete');
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', Role::class)) {
            return [];
        }

        $actions = [
            Action::link('create', 'New Role', route('manage.roles.create'))->icon('plus'),
        ];

        if (! Role::query()->exists()) {
            $actions[] = Action::post('seed', 'Create Default Roles', route('manage.roles.seed'))
                ->icon('sparkles')
                ->confirm(
                    'Create default roles',
                    'Creates Admin, Moderator, Super Sponsor, Sponsor and Attendee.',
                    'Create',
                );
        }

        return $actions;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function permissionOptions(): array
    {
        $options = [];

        foreach (self::PERMISSIONS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultRoles(): array
    {
        return [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full system administrator',
                'chat_color' => '#FF0000',
                'priority' => 100,
                'assigned_at_login' => false,
                'is_visible' => true,
                'permissions' => ['admin.access', 'stream.manage', 'user.manage', 'chat.moderate'],
            ],
            [
                'name' => 'Moderator',
                'slug' => 'moderator',
                'description' => 'Chat and stream moderator',
                'chat_color' => '#00FF00',
                'priority' => 90,
                'assigned_at_login' => false,
                'is_visible' => true,
                'permissions' => ['chat.moderate', 'chat.delete', 'chat.timeout', 'chat.slowmode'],
            ],
            [
                'name' => 'Super Sponsor',
                'slug' => 'super-sponsor',
                'description' => 'Super sponsor with a special chat colour',
                'chat_color' => '#FFD700',
                'priority' => 50,
                'assigned_at_login' => true,
                'is_visible' => true,
                'permissions' => [],
            ],
            [
                'name' => 'Sponsor',
                'slug' => 'sponsor',
                'description' => 'Sponsor with a chat colour',
                'chat_color' => '#C0C0C0',
                'priority' => 40,
                'assigned_at_login' => true,
                'is_visible' => true,
                'permissions' => [],
            ],
            [
                'name' => 'Attendee',
                'slug' => 'attendee',
                'description' => 'Regular attendee',
                'chat_color' => '#808080',
                'priority' => 10,
                'assigned_at_login' => true,
                'is_visible' => false,
                'permissions' => [],
            ],
        ];
    }
}
