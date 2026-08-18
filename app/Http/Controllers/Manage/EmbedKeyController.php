<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\EmbedKey;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Display keys: one row per screen or per person who needs a VLC URL.
 *
 * There is no edit form. A key is a name and a secret, and the secret is not
 * something to revise - if a screen should lose access, the key is deleted, which
 * is the whole revocation story.
 */
class EmbedKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EmbedKey::class);

        $table = Table::make(EmbedKey::query())
            ->name('embed-keys')
            ->columns([
                Column::text('name', 'Name')->searchable()->sortable(),
                Column::copyable('code', 'Code'),
                Column::copyable('url', 'Display URL'),
                Column::text('last_used', 'Last Used')->sortable('last_used_at'),
                Column::text('last_used_ip', 'Last Seen From')->toggleable(hiddenByDefault: true),
                Column::text('signed_out', 'Screens Signed Out')->toggleable(hiddenByDefault: true),
                Column::datetime('created_at', 'Created')->sortable()->toggleable(hiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->rows(fn (EmbedKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'code' => $key->key,
                'url' => $key->typableUrl(),
                'last_used' => $key->last_used_at?->diffForHumans() ?? 'Never',
                'last_used_ip' => $key->last_used_ip ?? '-',
                'signed_out' => $key->signed_out_at?->diffForHumans() ?? 'Never',
                'created_at' => $key->created_at,
            ])
            ->rowActions(fn (EmbedKey $key) => $this->rowActions($key))
            ->pageActions($this->pageActions());

        return inertia('Manage/EmbedKeys/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', EmbedKey::class);

        return inertia('Manage/EmbedKeys/Form', [
            'defaults' => ['name' => ''],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EmbedKey::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $key = EmbedKey::generate($validated['name']);

        Toast::flashSuccess(
            'Display key created',
            "Open {$key->typableUrl()} once on the screen for '{$key->name}'.",
        );

        return to_route('manage.embed-keys.index');
    }

    /**
     * Sign out whatever is currently using this key, keeping the key itself.
     *
     * The gentler half of revoking: a screen that was carried off or left signed in
     * loses its session and its playback tokens, and the screens that are fine only
     * have to be sent to the code again rather than issued a new one.
     */
    public function signOut(EmbedKey $embedKey): RedirectResponse
    {
        $this->authorize('update', $embedKey);

        $embedKey->signOutScreens();

        Toast::flashSuccess(
            'Screens signed out',
            "Anything using '{$embedKey->name}' stops playing and needs the code again.",
        );

        return to_route('manage.embed-keys.index');
    }

    public function destroy(EmbedKey $embedKey): RedirectResponse
    {
        $this->authorize('delete', $embedKey);

        $name = $embedKey->name;
        $embedKey->delete();

        Toast::flashSuccess('Display key revoked', "'{$name}' loses access on its next request.");

        return to_route('manage.embed-keys.index');
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(EmbedKey $key): array
    {
        if (! request()->user()->can('delete', $key)) {
            return [];
        }

        return [
            Action::post('sign-out', 'Sign out screens', route('manage.embed-keys.sign-out', $key))
                ->icon('log-out')
                ->confirm(
                    'Sign out screens',
                    "Anything using '{$key->name}' stops playing and has to be sent to the code again. The code itself keeps working.",
                    'Sign out',
                ),
            Action::delete('delete', 'Revoke', route('manage.embed-keys.destroy', $key))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Revoke display key',
                    "Any screen using '{$key->name}' stops playing within a few seconds.",
                    'Revoke',
                ),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', EmbedKey::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Display Key', route('manage.embed-keys.create'))->icon('plus'),
        ];
    }
}
