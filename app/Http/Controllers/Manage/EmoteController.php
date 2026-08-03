<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\EmoteRequest;
use App\Models\Emote;
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
 * Chat emotes and the approval queue in front of them.
 *
 * Rejecting is a delete, not a status: an unapproved emote has an uploaded image
 * behind it, and keeping rejected rows around means keeping those files too.
 */
class EmoteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Emote::class);

        $table = Table::make(Emote::query()->with('uploadedBy'))
            ->name('emotes')
            ->columns([
                Column::image('image', 'Emote'),
                Column::copyable('name', 'Name')->searchable('name'),
                Column::text('uploaded_by', 'Uploaded by'),
                Column::badge('is_approved', 'Approved'),
                Column::badge('is_global', 'Global'),
                Column::number('usage_count', 'Usage')->sortable(),
                Column::datetime('created_at', 'Uploaded')->sortable()->toggleable(),
            ])
            ->filters([
                Filter::select('approval_status', 'Status')
                    ->options(['pending' => 'Pending approval', 'approved' => 'Approved'])
                    ->placeholder('All statuses')
                    ->apply(fn ($query, $value) => $query->where('is_approved', $value === 'approved')),
                Filter::ternary('is_global', 'Global')
                    ->trueLabel('Global only')
                    ->falseLabel('Personal only')
                    ->placeholder('All emotes'),
            ])
            ->defaultSort('created_at', 'desc')
            ->rows(fn (Emote $emote) => $this->row($emote))
            ->recordUrl(fn (Emote $emote) => route('manage.emotes.edit', $emote))
            ->rowActions(fn (Emote $emote) => $this->rowActions($emote))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions());

        return inertia('Manage/Emotes/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Emote::class);

        return inertia('Manage/Emotes/Form', [
            'emote' => null,
            'defaults' => [
                'name' => '',
                's3_key' => '',
                'is_global' => true,
                'is_approved' => true,
            ],
        ]);
    }

    public function store(EmoteRequest $request): RedirectResponse
    {
        $this->authorize('create', Emote::class);

        $emote = Emote::create($request->validated() + [
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        // Uploaded from the panel by a moderator, so it is approved on the spot
        // rather than queued behind whoever just approved it.
        if ($request->boolean('is_approved')) {
            $emote->approve($request->user());
        }

        Toast::flashSuccess('Emote created', "':{$emote->name}:' is ready to use.");

        return to_route('manage.emotes.edit', $emote);
    }

    public function edit(Emote $emote): Response
    {
        $this->authorize('view', $emote);

        return inertia('Manage/Emotes/Form', [
            'emote' => [
                'id' => $emote->id,
                'name' => $emote->name,
                's3_key' => $emote->s3_key,
                'is_global' => (bool) $emote->is_global,
                'is_approved' => (bool) $emote->is_approved,
                'usage_count' => $emote->usage_count,
                'preview_url' => $emote->url,
                'uploaded_by' => $emote->uploadedBy?->name ?? '-',
                'approved_by' => $emote->approvedBy?->name ?? '-',
                'approved_at' => $emote->approved_at?->format('M j, Y H:i') ?? '-',
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($emote),
            ),
        ]);
    }

    public function update(EmoteRequest $request, Emote $emote): RedirectResponse
    {
        $this->authorize('update', $emote);

        $validated = $request->validated();
        $approve = $request->boolean('is_approved');
        unset($validated['is_approved']);

        $emote->update($validated);

        // Going through approve() rather than setting the flag keeps the approver
        // and the timestamp in step with the row.
        if ($approve && ! $emote->is_approved) {
            $emote->approve($request->user());
        }

        Toast::flashSuccess('Emote updated');

        return back();
    }

    public function destroy(Emote $emote): RedirectResponse
    {
        $this->authorize('delete', $emote);

        $name = $emote->name;
        // reject() removes the stored image along with the row.
        $emote->reject();

        Toast::flashSuccess('Emote deleted', "':{$name}:' and its image have been removed.");

        return to_route('manage.emotes.index');
    }

    public function approve(Emote $emote): RedirectResponse
    {
        $this->authorize('approve', $emote);

        $emote->approve(request()->user());

        Toast::flashSuccess('Emote approved', "':{$emote->name}:' is now usable in chat.");

        return back();
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $this->authorize('create', Emote::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $emotes = Emote::whereIn('id', $validated['ids'])->where('is_approved', false)->get();
        $emotes->each(fn (Emote $emote) => $emote->approve($request->user()));

        Toast::flashSuccess('Emotes approved', $emotes->count().' approved.');

        return back();
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('create', Emote::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $emotes = Emote::whereIn('id', $validated['ids'])->get();
        $emotes->each->reject();

        Toast::flashSuccess('Emotes deleted', $emotes->count().' removed with their images.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Emote $emote): array
    {
        return [
            'image' => $emote->url,
            'name' => ':'.$emote->name.':',
            'uploaded_by' => $emote->uploadedBy?->name ?? '-',
            'is_approved' => $emote->is_approved
                ? Status::make('Approved', Status::OK)
                : Status::make('Pending', Status::WARN),
            'is_global' => $emote->is_global
                ? Status::make('Global', Status::INFO)
                : Status::make('Personal', Status::IDLE),
            'usage_count' => $emote->usage_count,
            'created_at' => $emote->created_at?->format('M j, Y H:i'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Emote $emote): array
    {
        $user = request()->user();
        $actions = [];

        if (! $emote->is_approved && $user->can('approve', $emote)) {
            $actions[] = Action::post('approve', 'Approve', route('manage.emotes.approve', $emote))
                ->icon('check-circle')
                ->tone(Status::OK);
        }

        $actions[] = Action::link('edit', 'Edit', route('manage.emotes.edit', $emote))->icon('pencil');

        if ($user->can('delete', $emote)) {
            $actions[] = $this->deleteAction($emote);
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function recordActions(Emote $emote): array
    {
        return $this->rowActions($emote);
    }

    private function deleteAction(Emote $emote): Action
    {
        return Action::delete('delete', 'Delete', route('manage.emotes.destroy', $emote))
            ->icon('trash-2')
            ->tone(Status::DANGER)
            ->confirm(
                'Delete emote',
                "':{$emote->name}:' and its uploaded image are removed for good.",
                'Delete',
            );
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        if (! request()->user()->can('create', Emote::class)) {
            return [];
        }

        return [
            Action::post('bulk_approve', 'Approve', route('manage.emotes.bulk.approve'))
                ->icon('check-circle')
                ->tone(Status::OK)
                ->confirm('Approve selected emotes', 'Already approved emotes are skipped.', 'Approve'),
            Action::delete('bulk_delete', 'Delete', route('manage.emotes.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm('Delete selected emotes', 'Their uploaded images go too.', 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', Emote::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Emote', route('manage.emotes.create'))->icon('plus'),
        ];
    }
}
