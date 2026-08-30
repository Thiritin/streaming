<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\ArchiveProbe;
use App\Support\Manage\Settings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Test" on the archive storage pane, built to the same shape as the pretalx one so the
 * panel has one way of proving a connection rather than two.
 *
 * The values tested are the ones in the form, not the saved ones, which is what makes the
 * button worth pressing: credentials can be proven before they are committed. The two
 * write-only fields are never sent to the page, so a blank or masked one means "the
 * stored one", resolved exactly as a save resolves it - reading them any other way would
 * report a failure on a form nobody had touched.
 */
class ArchiveStorageTestController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('admin.access'), 403);

        $validated = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:2048'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'path_style' => ['nullable', 'boolean'],
        ]);

        $result = ArchiveProbe::run([
            'endpoint' => $validated['endpoint'] ?? null,
            'bucket' => $validated['bucket'] ?? null,
            'region' => $validated['region'] ?? null,
            'key' => $this->secret($validated, 'key', 'filesystems.disks.dvr.key'),
            'secret' => $this->secret($validated, 'secret', 'filesystems.disks.dvr.secret'),
            'path_style' => $validated['path_style'] ?? false,
        ]);

        if ($result['ok']) {
            Toast::flashSuccess('Working');

            return back();
        }

        Toast::flashDanger(self::TITLES[$result['stage']] ?? 'Failed', $result['message']);

        return back();
    }

    /**
     * What failed, and nothing about what the test covers.
     */
    private const TITLES = [
        'credentials' => 'Credentials rejected',
        'bucket' => 'Bucket not found',
        'write' => 'Write failed',
        'read' => 'Read failed',
        'delete' => 'Delete failed',
    ];

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
