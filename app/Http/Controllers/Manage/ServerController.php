<?php

namespace App\Http\Controllers\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ServerRequest;
use App\Models\Server;
use App\Models\SourceUser;
use App\Services\Hetzner;
use App\Services\ServerMetricsService;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ServerController extends Controller
{
    /**
     * Everything except `deleted`: the status filter's default, which is what keeps
     * torn-down servers out of the list.
     */
    private const VISIBLE_STATUSES = [
        ServerStatusEnum::ACTIVE->value,
        ServerStatusEnum::PROVISIONING->value,
        ServerStatusEnum::DEPROVISIONING->value,
        ServerStatusEnum::ERROR->value,
    ];

    /**
     * Deleted servers are hidden by default, as they were in Filament. There it was a hard
     * query scope, which made the "Deleted" choice in the status filter dead - selecting it
     * could only ever return nothing. Here the same default is expressed as the status
     * filter's default value instead, so the option actually works.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Server::class);

        $table = Table::make(Server::query())
            ->name('servers')
            ->columns([
                Column::text('hetzner_id', 'Server ID')->searchable()->fallback('-'),
                Column::badge('type', 'Type'),
                Column::text('server_type', 'Size')->toggleable()->fallback('-'),
                Column::copyable('hostname', 'Hostname')->searchable()->sortable(),
                Column::copyable('ip', 'IP'),
                Column::number('port', 'Port')->sortable(),
                Column::badge('status', 'Status'),
                Column::number('viewer_count', 'Viewers')->sortable(),
                Column::icon('heartbeat', 'Heartbeat'),
                Column::badge('health_status', 'Health'),
                Column::number('max_clients', 'Max clients')->sortable(),
            ])
            ->filters([
                Filter::select('status', 'Status')
                    ->options([
                        ServerStatusEnum::ACTIVE->value => 'Active',
                        ServerStatusEnum::PROVISIONING->value => 'Provisioning',
                        ServerStatusEnum::DEPROVISIONING->value => 'Deprovisioning',
                        ServerStatusEnum::DELETED->value => 'Deleted',
                        ServerStatusEnum::ERROR->value => 'Error',
                    ])
                    ->multiple()
                    ->default(self::VISIBLE_STATUSES)
                    ->apply(fn (Builder $query, array $value) => $query->whereIn('status', $value)),
                Filter::select('type', 'Type')
                    ->options([
                        ServerTypeEnum::ORIGIN->value => 'Origin',
                        ServerTypeEnum::EDGE->value => 'Edge',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->rows(fn (Server $server) => $this->row($server))
            ->recordUrl(fn (Server $server) => route('manage.servers.show', $server))
            ->rowActions(fn (Server $server) => $this->rowActions($server))
            ->pageActions($this->pageActions());

        return inertia('Manage/Servers/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Server::class);

        return inertia('Manage/Servers/Form', [
            'server' => null,
            'options' => $this->formOptions(),
            'defaults' => [
                'hostname' => '',
                'ip' => '',
                'port' => 8080,
                'type' => ServerTypeEnum::EDGE->value,
                'status' => ServerStatusEnum::ACTIVE->value,
                'max_clients' => 100,
                'hetzner_id' => '',
            ],
        ]);
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        $this->authorize('create', Server::class);

        $server = Server::create($request->serverData());

        Toast::flashSuccess('Server created', "'{$server->hostname}' is now managed here.");

        return to_route('manage.servers.show', $server);
    }

    /**
     * The server as it is: what it is doing now, what it has been doing, and where to
     * go to change it. Editing lives on its own page - most visits here are to read a
     * graph, and a form is the wrong thing to land on for that.
     */
    public function show(Request $request, Server $server, ServerMetricsService $metrics): Response
    {
        $this->authorize('view', $server);

        return inertia('Manage/Servers/Show', [
            'server' => $this->details($server),
            'metrics' => $metrics->forServer($server, $request->query('range')),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($server, includeEdit: true),
            ),
            'users' => $this->viewersOn($server),
        ]);
    }

    /**
     * Read-only view of a server, in the units an operator thinks in.
     *
     * @return array<string, mixed>
     */
    private function details(Server $server): array
    {
        $isEdge = $server->type === ServerTypeEnum::EDGE;

        return [
            'id' => $server->id,
            'hostname' => $server->hostname ?: 'Unnamed server',
            'ip' => $server->ip,
            'port' => $server->port,
            'hetzner_id' => $server->hetzner_id,
            'server_type' => $server->server_type,
            'type' => Status::serverType($server->type),
            'status' => Status::server($server->status),
            'health_status' => $isEdge ? Status::health($server->health_status) : null,
            'health_check_message' => $server->health_check_message,
            'last_health_check' => $server->last_health_check?->diffForHumans(),
            'is_edge' => $isEdge,
            'is_cloud' => $server->isHetznerServer(),
            'max_clients' => $server->max_clients,
            'viewer_count' => (int) $server->viewer_count,
            'heartbeat' => $this->heartbeatStatus($server),
            'last_heartbeat' => $server->last_heartbeat?->diffForHumans() ?? 'Never',
            'last_heartbeat_exact' => $server->last_heartbeat?->format('M j, Y H:i:s'),
            'created_at' => $server->created_at?->format('M j, Y H:i') ?? '-',
            'edit_url' => route('manage.servers.edit', $server),
            'index_url' => route('manage.servers.index'),
        ];
    }

    public function edit(Server $server): Response
    {
        $this->authorize('view', $server);

        return inertia('Manage/Servers/Form', [
            'server' => [
                'id' => $server->id,
                'hetzner_id' => $server->hetzner_id,
                'hostname' => $server->hostname,
                'ip' => $server->ip,
                'port' => $server->port,
                'type' => $server->type?->value,
                'status' => $server->status?->value,
                'credentials_rotated_at' => $server->shared_secret_rotated_at?->diffForHumans(),
                'max_clients' => $server->max_clients,
                'viewer_count' => $server->viewer_count,
                'health_status' => $server->health_status,
                'health_check_message' => $server->health_check_message,
                'created_at' => $server->created_at?->diffForHumans() ?? '-',
                'updated_at' => $server->updated_at?->diffForHumans() ?? '-',
                'is_cloud' => $server->isHetznerServer(),
                'is_edge' => $server->type === ServerTypeEnum::EDGE,
                'show_url' => route('manage.servers.show', $server),
            ],
            'options' => $this->formOptions(),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($server, includeEdit: false),
            ),
            'users' => $this->viewersOn($server),
        ]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $server->update($request->serverData($server->type));

        Toast::flashSuccess('Server updated');

        return back();
    }

    /**
     * Who is on this edge right now.
     *
     * Read from open `source_users` rows rather than from accounts. An assignment
     * belongs to a viewing session, so this counts signed-out viewers too - the figure
     * used to cover only signed-in ones and could not be reconciled with the viewer
     * count beside it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function viewersOn(Server $server): array
    {
        return $server->sessions()
            ->whereNull('left_at')
            ->with('user:id,name,sub,reg_id')
            ->latest('joined_at')
            ->get()
            ->map(fn (SourceUser $session) => [
                'id' => $session->id,
                'name' => $session->user?->name ?? 'Guest',
                'sub' => $session->user?->sub ?? '-',
                'reg_id' => $session->user?->reg_id,
            ])
            ->all();
    }

    /**
     * Only for manually managed servers; the policy blocks anything with a hetzner_id.
     * Server::delete() unassigns its users first.
     */
    public function destroy(Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $hostname = $server->hostname;
        $server->delete();

        Toast::flashSuccess('Server deleted', "'{$hostname}' has been removed.");

        return to_route('manage.servers.index');
    }

    public function deprovision(Server $server): RedirectResponse
    {
        $this->authorize('deprovision', $server);

        $server->deprovision();

        Toast::flashSuccess(
            'Deprovisioning started',
            "'{$server->hostname}' is being torn down on Hetzner Cloud.",
        );

        return back();
    }

    /**
     * For a teardown that stalled: re-run the half that deletes the cloud resources.
     * The policy only offers this on a row already sitting in `deprovisioning`.
     */
    public function forceDeprovision(Server $server): RedirectResponse
    {
        $this->authorize('forceDeprovision', $server);

        $server->forceDeprovision();

        Toast::flashSuccess(
            'Teardown restarted',
            "Deleting '{$server->hostname}' on Hetzner Cloud and removing its DNS record.",
        );

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Server $server): array
    {
        $isEdge = $server->type === ServerTypeEnum::EDGE;
        $capacity = $isEdge && $server->max_clients > 0
            ? round($server->viewer_count / $server->max_clients * 100).'% capacity'
            : null;

        return [
            'hetzner_id' => $server->hetzner_id,
            'type' => Status::serverType($server->type),
            // Null for anything provisioned before the size was recorded; the fallback
            // renders '-' rather than implying a size nobody chose.
            'server_type' => $server->server_type,
            'hostname' => $server->hostname,
            'ip' => $server->ip,
            'port' => $server->port,
            'status' => Status::server($server->status),
            'viewer_count' => $isEdge
                ? ['display' => number_format($server->viewer_count, 0, '.', ' '), 'description' => $capacity]
                : null,
            'heartbeat' => $this->heartbeatStatus($server),
            // Health checks only run against edge servers, so an origin shows nothing
            // rather than a misleading "unknown".
            'health_status' => $isEdge ? Status::health($server->health_status) : null,
            'max_clients' => $server->max_clients,
        ];
    }

    /**
     * A refused credential and a dead box both stop the heartbeat, so they would read
     * the same. This one is knowable - the app is the side turning the request away -
     * and it is the more specific answer, so it wins.
     *
     * @return array<string, mixed>
     */
    private function heartbeatStatus(Server $server): array
    {
        if ($server->credential_rejected_at) {
            return Status::make('Credentials rejected', Status::DANGER, 'circle-x') + [
                'title' => 'Credentials rejected '.$server->credential_rejected_at->diffForHumans(),
            ];
        }

        if ($server->hasRecentHeartbeat()) {
            return Status::make('Recent', Status::OK, 'circle-check') + [
                'title' => 'Last heartbeat: '.$server->last_heartbeat->diffForHumans(),
            ];
        }

        return Status::make('Stale', Status::DANGER, 'circle-x') + [
            'title' => $server->last_heartbeat
                ? 'Last heartbeat: '.$server->last_heartbeat->diffForHumans()
                : 'No heartbeat received',
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Server $server): array
    {
        return $this->recordActions($server, includeEdit: true);
    }

    /**
     * Deprovision and Delete are mutually exclusive by design: a cloud server has to go
     * through Hetzner teardown, a manual one is just a row.
     *
     * @return array<int, Action>
     */
    private function recordActions(Server $server, bool $includeEdit): array
    {
        $user = request()->user();
        $actions = [];

        if ($includeEdit) {
            $actions[] = Action::link('edit', 'Edit', route('manage.servers.edit', $server))
                ->icon('pencil');
        }

        if ($user->can('viewInstallScript', $server)) {
            $actions[] = Action::link('install_script', 'Install Script', route('manage.servers.install-script', $server))
                ->icon('code');
        }

        if ($user->can('deprovision', $server)) {
            $actions[] = Action::post('deprovision', 'Deprovision', route('manage.servers.deprovision', $server))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Deprovision server',
                    'The Hetzner server and its DNS record are deleted. Viewers on it are moved away.',
                    'Deprovision',
                );
        }

        if ($user->can('forceDeprovision', $server)) {
            $actions[] = Action::post('force_deprovision', 'Force Teardown', route('manage.servers.force-deprovision', $server))
                ->icon('zap')
                ->tone(Status::DANGER)
                ->confirm(
                    'Force teardown',
                    'This server is already being deprovisioned but has not finished. '
                        .'Deletes the Hetzner server and its DNS record now, and ignores a DNS failure.',
                    'Force teardown',
                );
        }

        if ($user->can('delete', $server)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.servers.destroy', $server))
                ->icon('x')
                ->tone(Status::DANGER)
                ->confirm(
                    'Delete Manual Server',
                    'Are you sure you want to delete this manually managed server?',
                    'Delete',
                );
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        $user = request()->user();
        $actions = [];

        if ($user->can('create', Server::class)) {
            $actions[] = Action::link('create', 'New Manual Server', route('manage.servers.create'))
                ->icon('plus');
        }

        if ($user->can('provision', Server::class)) {
            $actions[] = Action::post('provision', 'Provision Cloud Server', route('manage.servers.provision'))
                ->icon('cloud')
                ->tone(Status::INFO)
                ->confirm(
                    'Provision New Cloud Server',
                    'Select the type of server to provision on Hetzner Cloud.',
                    'Start Provisioning',
                )
                ->fields([
                    [
                        'key' => 'type',
                        'label' => 'Role',
                        'type' => 'select',
                        'default' => ServerTypeEnum::EDGE->value,
                        'required' => true,
                        'helper' => 'Origin servers handle stream ingestion and transcoding. Edge servers cache and distribute content.',
                        'options' => [
                            ['value' => ServerTypeEnum::ORIGIN->value, 'label' => 'Origin Server'],
                            ['value' => ServerTypeEnum::EDGE->value, 'label' => 'Edge Server'],
                        ],
                    ],
                    [
                        'key' => 'server_type',
                        'label' => 'Instance Size',
                        'type' => 'select',
                        // Defaults to the edge size because the role above does, and the
                        // two are chosen together. Picking Origin without changing this
                        // is caught by the mismatch note in the helper rather than by
                        // silently overriding the operator.
                        'default' => config('stream.server.defaults.edge'),
                        'required' => true,
                        'helper' => 'Hetzner bills hourly, so this is the main cost lever: over a two week event the '
                            .'gap between ccx33 and ccx43 is roughly EUR 70. Origins need dedicated (ccx) cores for the '
                            .'x264 ladder - three encodes per live source. Edges are bandwidth-bound, so shared (cpx) is fine.',
                        'options' => $this->serverTypeOptions(),
                    ],
                ]);
        }

        return $actions;
    }

    /**
     * Instance sizes offered by the provision dialog, marked with the role each is the
     * default for so an operator does not have to remember which is which.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function serverTypeOptions(): array
    {
        $defaults = array_flip(config('stream.server.defaults', []));

        return collect(Hetzner::availableServerTypes())
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => isset($defaults[$value])
                    ? $label.' - default for '.$defaults[$value]
                    : $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => [
                ['value' => ServerTypeEnum::ORIGIN->value, 'label' => 'Origin'],
                ['value' => ServerTypeEnum::EDGE->value, 'label' => 'Edge'],
            ],
            'statuses' => [
                ['value' => ServerStatusEnum::PROVISIONING->value, 'label' => 'Provisioning'],
                ['value' => ServerStatusEnum::ACTIVE->value, 'label' => 'Active'],
                ['value' => ServerStatusEnum::DEPROVISIONING->value, 'label' => 'Deprovisioning'],
                ['value' => ServerStatusEnum::DELETED->value, 'label' => 'Deleted'],
                ['value' => ServerStatusEnum::ERROR->value, 'label' => 'Error'],
            ],
        ];
    }
}
