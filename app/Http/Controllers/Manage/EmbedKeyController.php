<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\DisplayScreen;
use App\Models\EmbedKey;
use App\Models\Source;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $table = Table::make(EmbedKey::query()->with(['screens.currentSource']))
            ->name('embed-keys')
            ->columns([
                Column::text('name', 'Name')->searchable()->sortable(),
                Column::copyable('code', 'Code'),
                Column::copyable('url', 'Display URL'),
                Column::text('screens', 'Screens'),
                Column::text('playing', 'Playing'),
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
                'screens' => (string) $this->present($key)->count(),
                'playing' => $this->playingSummary($key),
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

    /**
     * Send every screen on this code to a source.
     *
     * The per-screen version lives on the Screens page. This one is here because a
     * code is usually one room's worth of screens, and "put Hall 2 on the main stage"
     * is the sentence somebody actually says.
     */
    public function direct(Request $request, EmbedKey $embedKey): RedirectResponse
    {
        $this->authorize('update', $embedKey);

        $validated = $request->validate([
            'source_id' => ['nullable'],
        ]);

        $sourceId = $validated['source_id'] ?? null;
        $source = $sourceId ? Source::findOrFail($sourceId) : null;

        $screens = $this->present($embedKey);
        $screens->each(fn (DisplayScreen $screen) => $screen->directTo($source, $request->user()));

        Toast::flashSuccess(
            $source ? 'Screens sent' : 'Instructions cleared',
            $source
                ? $screens->count()." screens on '{$embedKey->name}' switch to {$source->name} within about ten seconds."
                : $screens->count()." screens on '{$embedKey->name}' stay where they are.",
        );

        return back();
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

        $present = $this->present($key);

        return [
            Action::post('send', 'Send Screens To Source', route('manage.embed-keys.direct', $key))
                ->icon('monitor-play')
                ->disabled($present->isEmpty() ? 'No screen on this code is polling.' : null)
                ->fields([[
                    'key' => 'source_id',
                    'label' => 'Source',
                    'type' => 'select',
                    'options' => $this->sourceOptions(),
                    'helper' => $present->count().' screen(s) switch within about ten seconds.',
                ]]),
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
     * The screens on this code that are currently polling.
     *
     * @return Collection<int, DisplayScreen>
     */
    private function present(EmbedKey $key): Collection
    {
        return $key->screens->filter(fn (DisplayScreen $screen) => $screen->isPresent());
    }

    /**
     * What this code's screens are showing, collapsed to one cell.
     */
    private function playingSummary(EmbedKey $key): string
    {
        $names = $this->present($key)
            ->map(fn (DisplayScreen $screen) => $screen->page === 'play'
                ? ($screen->currentSource?->name ?? 'Unknown')
                : 'Setup screen')
            ->countBy()
            ->map(fn (int $count, string $name) => $count > 1 ? "{$name} x{$count}" : $name)
            ->values();

        return $names->isEmpty() ? '-' : $names->implode(', ');
    }

    /**
     * Action fields take a list of {value, label}, led by the option that withdraws
     * an instruction rather than issuing one.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function sourceOptions(): array
    {
        $sources = Source::query()
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])
            ->all();

        return collect(['' => 'Leave where they are'] + $sources)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
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
