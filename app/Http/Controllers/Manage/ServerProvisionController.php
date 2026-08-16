<?php

namespace App\Http\Controllers\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Models\Server;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Provisioning a Hetzner server.
 *
 * A header action on the Filament servers list, separate from ServerController because it
 * does not act on a record.
 */
class ServerProvisionController extends Controller
{
    /**
     * The panel picks both the role and the instance size.
     *
     * Size used to be hardcoded in the provisioning job, which made it a deploy-time
     * decision for something Hetzner bills by the hour - the gap between ccx33 and ccx43
     * over a two-week event is around €70. It is validated against the configured list
     * rather than accepted freely: the field reaches the Hetzner API, so an operator
     * should not be able to name an arbitrary (or enormous) instance through it.
     *
     * The single-origin rule is enforced here rather than in validation because it
     * depends on live state and needs to explain itself in a toast.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('provision', Server::class);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ServerTypeEnum::class)],
            'server_type' => ['nullable', 'string', Rule::in(array_keys(config('stream.server.types', [])))],
        ]);

        $type = ServerTypeEnum::from($validated['type']);

        if ($type === ServerTypeEnum::ORIGIN && $this->originExists()) {
            Toast::flashDanger(
                'Cannot Create Origin Server',
                'An origin server already exists or is being provisioned. Only one origin server is allowed.',
            );

            return back();
        }

        $role = $type === ServerTypeEnum::ORIGIN ? 'origin' : 'edge';

        $serverType = $validated['server_type']
            ?? config("stream.server.defaults.{$role}");

        $server = Server::create([
            'type' => $type,
            'server_type' => $serverType,
            'status' => ServerStatusEnum::PROVISIONING,
            'hostname' => 'pending',
            'port' => 443,
            'shared_secret' => Str::random(40),
            'max_clients' => config("stream.server.max_clients.{$role}"),
        ]);

        CreateVirtualMachineJob::dispatch($server);

        Toast::flashSuccess(
            'Server Provisioning Started',
            "A new {$type->value} server ({$serverType}) is being provisioned on Hetzner Cloud.",
        );

        return back();
    }

    private function originExists(): bool
    {
        return Server::query()
            ->where('type', ServerTypeEnum::ORIGIN)
            ->whereIn('status', [ServerStatusEnum::ACTIVE, ServerStatusEnum::PROVISIONING])
            ->exists();
    }
}
