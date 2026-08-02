<?php

namespace App\Http\Controllers\Manage;

use App\Enum\StreamStatusEnum;
use App\Events\StreamStatusEvent;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Support\Manage\Action;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * The global stream state, which is what drives provisioning: moving to
 * "starting soon" boots the edge fleet and moving to offline tears it down.
 *
 * The state is broadcast, not stored, so this page offers the four transitions
 * and nothing else. What the fleet is actually doing is on the dashboard.
 */
class StreamController extends Controller
{
    public function show(): Response
    {
        $this->authorize('viewAny', Server::class);

        return inertia('Manage/Stream', [
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->actions(),
            ),
        ]);
    }

    public function update(StreamStatusEnum $status): RedirectResponse
    {
        $this->authorize('create', Server::class);

        event(new StreamStatusEvent($status));

        Toast::flashSuccess('Stream status set', $this->label($status).' has been broadcast.');

        return back();
    }

    /**
     * @return array<int, Action>
     */
    private function actions(): array
    {
        if (! request()->user()->can('create', Server::class)) {
            return [];
        }

        return [
            $this->transition(
                StreamStatusEnum::STARTING_SOON,
                'Set Starting Soon',
                Status::WARN,
                'Provisions the edge servers. Takes around six minutes before anyone can watch.',
            ),
            $this->transition(
                StreamStatusEnum::ONLINE,
                'Set Online',
                Status::LIVE,
                'Set this once the encoder is actually pushing.',
            ),
            $this->transition(
                StreamStatusEnum::TECHNICAL_ISSUE,
                'Set Technical Issue',
                Status::WARN,
                'Shown to viewers as a problem on our side. Set automatically when the stream drops.',
            ),
            $this->transition(
                StreamStatusEnum::OFFLINE,
                'Set Offline',
                Status::DANGER,
                'Ends the broadcast and deletes every provisioned server.',
            ),
        ];
    }

    private function transition(StreamStatusEnum $status, string $label, string $tone, string $description): Action
    {
        return Action::post(
            $status->value,
            $label,
            route('manage.stream.update', $status->value),
        )
            ->tone($tone)
            ->confirm($label.'?', $description, 'Confirm');
    }

    private function label(StreamStatusEnum $status): string
    {
        return Status::stream($status)['label'] ?? $status->value;
    }
}
