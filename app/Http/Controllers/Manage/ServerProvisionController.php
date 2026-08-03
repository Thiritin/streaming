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
     * Sizes are decided by the provisioning jobs; the panel only picks a role. The
     * single-origin rule is enforced here rather than in validation because it depends on
     * live state and needs to explain itself in a toast.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('provision', Server::class);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ServerTypeEnum::class)],
        ]);

        $type = ServerTypeEnum::from($validated['type']);

        if ($type === ServerTypeEnum::ORIGIN && $this->originExists()) {
            Toast::flashDanger(
                'Cannot Create Origin Server',
                'An origin server already exists or is being provisioned. Only one origin server is allowed.',
            );

            return back();
        }

        $server = Server::create([
            'type' => $type,
            'status' => ServerStatusEnum::PROVISIONING,
            'hostname' => 'pending',
            'port' => 443,
            'shared_secret' => Str::random(40),
            'max_clients' => $type === ServerTypeEnum::ORIGIN ? 1000 : 100,
        ]);

        CreateVirtualMachineJob::dispatch($server);

        Toast::flashSuccess(
            'Server Provisioning Started',
            "A new {$type->value} server is being provisioned on Hetzner Cloud.",
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
