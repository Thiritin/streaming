<?php

namespace App\Http\Controllers\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use App\Support\DnsSettings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Provisioning a server through whichever provider the installation is set to.
 *
 * A page action on the servers list, separate from ServerController because it does not
 * act on a record.
 */
class ServerProvisionController extends Controller
{
    /**
     * The panel picks the role, and the instance size when the provider has a catalogue.
     *
     * Size used to be hardcoded in the provisioning job, which made it a deploy-time
     * decision for something billed by the hour - the gap between ccx33 and ccx43 over a
     * two-week event is around EUR 70. It is validated against the provider's own list
     * rather than accepted freely: the field reaches a cloud API, so an operator should
     * not be able to name an arbitrary (or enormous) instance through it. A provider
     * with no catalogue - bring your own server - asks for an address instead, and the
     * size rule is skipped rather than failing against an empty list.
     *
     * The single-origin rule is enforced here rather than in validation because it
     * depends on live state and needs to explain itself in a toast.
     */
    public function store(Request $request, CloudManager $cloud): RedirectResponse
    {
        $this->authorize('provision', Server::class);

        $provider = $cloud->driver();
        $sizes = $provider->sizes();

        $rules = [
            'type' => ['required', Rule::enum(ServerTypeEnum::class)],
            'server_type' => ['nullable', 'string'],
        ];

        if ($sizes !== []) {
            $rules['server_type'][] = Rule::in(array_keys($sizes));
        }

        if (! $provider->supportsProvisioning()) {
            // A hostname, and one inside the zone this installation writes. The drivers
            // refuse anything stranger than a hostname, but an out-of-zone name would
            // still be accepted here and then written by the API drivers as a mangled
            // label inside our own zone.
            $rules['hostname'] = [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/',
                ...($zone = DnsSettings::zone()) === '' ? [] : ['ends_with:.'.$zone],
            ];
            $rules['ip'] = ['required', 'ip'];
        }

        $validated = $request->validate($rules);

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
            'provider' => $provider->name(),
            'server_type' => $provider->supportsProvisioning() ? $serverType : null,
            'status' => ServerStatusEnum::PROVISIONING,
            'hostname' => $validated['hostname'] ?? 'pending',
            'ip' => $validated['ip'] ?? null,
            'port' => 443,
            'max_clients' => config("stream.server.max_clients.{$role}"),
        ]);

        CreateVirtualMachineJob::dispatch($server);

        Toast::flashSuccess(
            'Server Provisioning Started',
            $provider->supportsProvisioning()
                ? "A new {$type->value} server ({$serverType}) is being provisioned."
                : "A new {$type->value} server is registered. Run its install script on the machine.",
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
