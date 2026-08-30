<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Services\Dns\DnsManager;
use App\Support\Manage\Settings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Test" on the DNS card, built to the same shape as the pretalx and storage ones.
 *
 * The values tested are the ones in the form, saved or not, which is what makes the
 * button worth pressing before a driver switch rather than after. Write-only fields are
 * never sent to the page, so a blank or masked one falls back to the stored value
 * exactly as a save resolves it.
 */
class DnsConnectionTestController extends Controller
{
    public function __invoke(Request $request, DnsManager $dns): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('admin.access'), 403);

        $validated = $request->validate([
            'driver' => ['required', 'string', 'in:rfc2136,cloudflare,hetzner,none'],
            // Same rules the pane applies. These reach nsupdate's script by way of the
            // driver, so the test button must not be the way past the pane's validation.
            'zone' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'server' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.:-]*[A-Za-z0-9])?$/'],
            'key_name' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$/'],
            // The same closed set the pane applies. It is interpolated into the key
            // file's `algorithm %s;`, so a free string here writes a TSIG clause the
            // pane itself would refuse.
            'key_algorithm' => ['nullable', 'string', 'in:hmac-sha256,hmac-sha512,hmac-sha1,hmac-md5'],
            'key_secret' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9+\/=]+$/'],
            'cloudflare_token' => ['nullable', 'string', 'max:255'],
            'cloudflare_zone_id' => ['nullable', 'string', 'max:64'],
            'hetzner_token' => ['nullable', 'string', 'max:255'],
            'hetzner_zone_id' => ['nullable', 'string', 'max:64'],
        ]);

        // The overlay is per process and the driver reads config, so the form's values
        // stand in for the saved ones for the length of this request and nothing else.
        config([
            'dns.zone' => $validated['zone'] ?? config('dns.zone'),
            'dns.server' => $validated['server'] ?? config('dns.server'),
            'dns.key_name' => $validated['key_name'] ?? config('dns.key_name'),
            'dns.key_algorithm' => $validated['key_algorithm'] ?? config('dns.key_algorithm'),
            'dns.key_secret' => $this->secret($validated, 'key_secret', 'dns.key_secret'),
            'dns.cloudflare.token' => $this->secret($validated, 'cloudflare_token', 'dns.cloudflare.token'),
            'dns.cloudflare.zone_id' => $validated['cloudflare_zone_id'] ?? config('dns.cloudflare.zone_id'),
            'dns.hetzner.token' => $this->secret($validated, 'hetzner_token', 'dns.hetzner.token'),
            'dns.hetzner.zone_id' => $validated['hetzner_zone_id'] ?? config('dns.hetzner.zone_id'),
        ]);

        $result = $dns->driver($validated['driver'])->check();

        if ($result->ok) {
            Toast::flashSuccess('Working', $this->summary($result->message, $result->details));

            return back();
        }

        Toast::flashDanger('Failed', $this->summary($result->message, $result->details));

        return back();
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
