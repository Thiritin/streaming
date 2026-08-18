<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\DisplayScreen;
use App\Models\Source;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The screens themselves, one level below the keys that let them in.
 *
 * A key can be on four walls, so this is where "what is Hall 2 showing" and "put
 * every screen on the main stage" live. Sending a screen somewhere writes an
 * instruction on its row; the screen picks it up on its next poll, within about ten
 * seconds, and clears it by reporting that it arrived. Nothing is pushed - a screen
 * on a hotel TV behind three NATs has no address to push to.
 *
 * A screen that stops polling stops being listed. That is deliberate: an operator
 * choosing where to send a wall should only ever be offered walls that are alive.
 */
class DisplayScreenController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DisplayScreen::class);

        $table = Table::make(
            DisplayScreen::query()->with(['embedKey', 'currentSource', 'directedSource'])
        )
            ->name('display-screens')
            ->columns([
                Column::text('name', 'Screen')->searchable('label')->sortable('label'),
                Column::text('key_name', 'Key'),
                Column::badge('playing', 'Playing'),
                Column::text('directed', 'Sent To'),
                Column::badge('presence', 'State')->sortable('last_seen_at'),
                Column::text('last_seen', 'Last Seen')->sortable('last_seen_at'),
                Column::text('last_ip', 'Address')->toggleable(hiddenByDefault: true),
                Column::text('user_agent', 'Browser')->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::select('presence', 'State')
                    ->options(['present' => 'Live now', 'gone' => 'Not polling'])
                    ->default('present')
                    ->placeholder('Any')
                    ->apply(fn ($query, $value) => match ($value) {
                        'present' => $query->present(),
                        'gone' => $query->where(fn ($q) => $q
                            ->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', now()->subMinutes(DisplayScreen::PRESENT_MINUTES))),
                        default => null,
                    }),
                Filter::select('current_source_id', 'Playing')
                    ->options($this->sourceOptions())
                    ->placeholder('Any source'),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->rows(fn (DisplayScreen $screen) => $this->row($screen))
            ->rowActions(fn (DisplayScreen $screen) => $this->rowActions($screen))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions());

        return inertia('Manage/Displays/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    /**
     * Send one screen to a source, or clear the instruction and leave it where it is.
     */
    public function direct(Request $request, DisplayScreen $displayScreen): RedirectResponse
    {
        $this->authorize('update', $displayScreen);

        $source = $this->requestedSource($request);

        $displayScreen->directTo($source, $request->user());

        Toast::flashSuccess(
            $source ? 'Screen sent' : 'Instruction cleared',
            $source
                ? "{$displayScreen->displayName()} switches to {$source->name} within about ten seconds."
                : "{$displayScreen->displayName()} stays on whatever it is playing.",
        );

        return back();
    }

    public function bulkDirect(Request $request): RedirectResponse
    {
        $this->authorize('create', DisplayScreen::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $source = $this->requestedSource($request);
        $screens = DisplayScreen::whereIn('id', $validated['ids'])->get();

        $this->directAllOf($screens, $source, $request);

        return back();
    }

    /**
     * Every screen that is currently polling, in one go.
     *
     * The reason this exists as its own button: when the main stage opens, the answer
     * is "all of them", and picking twenty checkboxes with the doors already open is
     * not the moment to be counting rows.
     */
    public function directAll(Request $request): RedirectResponse
    {
        $this->authorize('create', DisplayScreen::class);

        $source = $this->requestedSource($request);

        $this->directAllOf(DisplayScreen::query()->present()->get(), $source, $request);

        return back();
    }

    public function rename(Request $request, DisplayScreen $displayScreen): RedirectResponse
    {
        $this->authorize('update', $displayScreen);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $displayScreen->forceFill(['label' => $validated['label'] ?: null])->save();

        Toast::flashSuccess('Screen renamed', "Now listed as '{$displayScreen->displayName()}'.");

        return back();
    }

    /**
     * Forget a screen. It is a record of a session, not a permission, so this is
     * housekeeping: a screen still holding the session reappears on its next poll.
     */
    public function destroy(DisplayScreen $displayScreen): RedirectResponse
    {
        $this->authorize('delete', $displayScreen);

        $name = $displayScreen->displayName();
        $displayScreen->delete();

        Toast::flashSuccess('Screen removed', "'{$name}' is off the list until it polls again.");

        return back();
    }

    /**
     * @param  Collection<int, DisplayScreen>  $screens
     */
    private function directAllOf(Collection $screens, ?Source $source, Request $request): void
    {
        $screens->each(fn (DisplayScreen $screen) => $screen->directTo($source, $request->user()));

        Toast::flashSuccess(
            $source ? 'Screens sent' : 'Instructions cleared',
            $source
                ? $screens->count()." screens switch to {$source->name} within about ten seconds."
                : $screens->count().' screens stay on whatever they are playing.',
        );
    }

    /**
     * The chosen source, or null for the "leave it alone" option every select carries.
     */
    private function requestedSource(Request $request): ?Source
    {
        $validated = $request->validate([
            'source_id' => ['nullable'],
        ]);

        $id = $validated['source_id'] ?? null;

        return $id ? Source::findOrFail($id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(DisplayScreen $screen): array
    {
        $present = $screen->isPresent();

        return [
            'id' => $screen->id,
            'name' => $screen->displayName(),
            'key_name' => $screen->embedKey?->name ?? '-',
            'playing' => $screen->page === 'play'
                ? Status::make(
                    $screen->currentSource?->name ?? 'Unknown',
                    $screen->currentSource ? Status::LIVE : Status::IDLE,
                    'monitor-play',
                )
                : Status::make('On setup screen', Status::IDLE, 'cog'),
            'directed' => $screen->directedSource
                ? $screen->directedSource->name.' (pending)'
                : '-',
            'presence' => Status::make(
                $present ? 'Live' : 'Not polling',
                $present ? Status::OK : Status::DANGER,
            ),
            'last_seen' => $screen->last_seen_at?->diffForHumans() ?? 'Never',
            'last_ip' => $screen->last_ip ?? '-',
            'user_agent' => $screen->user_agent ?? '-',
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(DisplayScreen $screen): array
    {
        $user = request()->user();
        $actions = [];

        if ($user->can('update', $screen)) {
            $actions[] = Action::post('send', 'Send To Source', route('manage.displays.direct', $screen))
                ->icon('monitor-play')
                ->tone(Status::INFO)
                ->disabled($screen->isPresent() ? null : 'This screen has stopped polling.')
                ->fields([[
                    'key' => 'source_id',
                    'label' => 'Source',
                    'type' => 'select',
                    'default' => (string) ($screen->directed_source_id ?? $screen->current_source_id ?? ''),
                    'options' => $this->sourceOptionList(),
                    'helper' => $screen->page === 'play'
                        ? 'The screen switches on its next poll, within about ten seconds.'
                        : 'This screen is on the setup page, so it shows a prompt instead of switching by itself.',
                ]]);

            $actions[] = Action::post('rename', 'Rename', route('manage.displays.rename', $screen))
                ->icon('pencil')
                ->fields([[
                    'key' => 'label',
                    'label' => 'Screen name',
                    'type' => 'text',
                    'default' => $screen->label ?? '',
                    'helper' => 'What this screen is called here. Blank falls back to the key name.',
                ]]);
        }

        if ($user->can('delete', $screen)) {
            $actions[] = Action::delete('forget', 'Forget', route('manage.displays.destroy', $screen))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Forget screen',
                    'Only removes the row. A screen still signed in reappears on its next poll; sign the key out to actually lock it out.',
                    'Forget',
                );
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        if (! request()->user()->can('create', DisplayScreen::class)) {
            return [];
        }

        return [
            Action::post('bulk_send', 'Send To Source', route('manage.displays.bulk.direct'))
                ->icon('monitor-play')
                ->tone(Status::INFO)
                ->fields([[
                    'key' => 'source_id',
                    'label' => 'Source',
                    'type' => 'select',
                    'options' => $this->sourceOptionList(),
                ]]),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', DisplayScreen::class)) {
            return [];
        }

        return [
            Action::post('send_all', 'Send All Screens', route('manage.displays.direct-all'))
                ->icon('monitor-play')
                ->tone(Status::WARN)
                ->fields([[
                    'key' => 'source_id',
                    'label' => 'Source',
                    'type' => 'select',
                    'options' => $this->sourceOptionList(),
                    'helper' => 'Every screen currently polling, including ones somebody is standing at.',
                ]])
                ->confirm(
                    'Send every screen',
                    'Every display that is currently polling switches to the chosen source.',
                    'Send',
                ),
        ];
    }

    /**
     * Filters take a value => label map.
     *
     * @return array<string, string>
     */
    private function sourceOptions(): array
    {
        return Source::query()
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])
            ->all();
    }

    /**
     * Action fields take a list of {value, label}, and lead with the option that
     * withdraws an instruction rather than issuing one.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function sourceOptionList(): array
    {
        return collect(['' => 'Leave where it is'] + $this->sourceOptions())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
