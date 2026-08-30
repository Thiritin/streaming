<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use App\Support\Manage\Settings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Test" on the cloud provider card, the same shape as the DNS and storage ones.
 *
 * It also counts what a switch would leave behind. Servers keep being managed by the
 * driver named on their row, which is what stops a change here stranding a running
 * machine - but somebody about to change it should be told how many rows that is.
 */
class CloudConnectionTestController extends Controller
{
    public function __invoke(Request $request, CloudManager $cloud): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('admin.access'), 403);

        $validated = $request->validate([
            'driver' => ['required', 'string', 'in:hetzner,manual'],
            'token' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:32'],
        ]);

        config([
            'services.hetzner.token' => $this->secret($validated, 'token', 'services.hetzner.token'),
            'stream.server.location' => $validated['location'] ?? config('stream.server.location'),
        ]);

        $result = $cloud->driver($validated['driver'])->check();

        $details = $result->details;

        foreach ($this->elsewhere($validated['driver']) as $provider => $count) {
            $details['Still on '.$provider] = $count.' '.($count === 1 ? 'server' : 'servers');
        }

        $message = $this->summary($result->message, $details);

        if ($result->ok) {
            Toast::flashSuccess('Working', $message);

            return back();
        }

        Toast::flashDanger('Failed', $message);

        return back();
    }

    /**
     * Servers built by a provider other than the one being tested. They keep being
     * managed by the driver on their row; this only says how many there are.
     *
     * @return array<string, int>
     */
    private function elsewhere(string $driver): array
    {
        return Server::query()
            ->whereNotIn('status', ['deleted'])
            ->where('provider', '!=', $driver)
            ->selectRaw('provider, count(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  array<string, string>  $details
     */
    private function summary(string $message, array $details): string
    {
        if ($details === []) {
            return $message;
        }

        return $message.' '.collect($details)
            ->map(fn (string $value, string $label) => "{$label}: {$value}")
            ->implode('. ').'.';
    }

    /**
     * A posted write-only value, or the stored one. Same reading as Settings::save():
     * blank and the mask both mean "leave it alone", so both fall back.
     *
     * @param  array<string, mixed>  $values
     */
    private function secret(array $values, string $key, string $configPath): string
    {
        $posted = trim((string) ($values[$key] ?? ''));

        if ($posted === '' || $posted === Settings::MASK_SECRET) {
            return (string) config($configPath);
        }

        if ($posted === Settings::CLEAR_SECRET) {
            return '';
        }

        return $posted;
    }
}
