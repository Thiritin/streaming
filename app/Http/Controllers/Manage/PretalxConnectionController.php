<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Services\PretalxService;
use App\Support\Manage\Settings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * "Test connection" on the settings page.
 *
 * Answers two questions in one round trip: do these credentials reach the instance, and
 * which events do they see. The event list is remembered so the slug can be picked from a
 * dropdown afterwards rather than typed from memory.
 *
 * The values tested are the ones currently in the form, not the saved ones, so a
 * connection can be proven before it is stored. A blank or masked token falls back to the
 * stored one, because the page is never given the real value to send back.
 */
class PretalxConnectionController extends Controller
{
    public function __invoke(Request $request, PretalxService $pretalx): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('admin.access'), 403);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'event' => ['nullable', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        $token = trim((string) ($validated['token'] ?? ''));

        if ($token === Settings::MASK_SECRET || $token === Settings::CLEAR_SECRET) {
            $token = '';
        }

        $client = $pretalx->using([
            'pretalx_url' => $validated['url'],
            'pretalx_event' => $validated['event'] ?? null,
            // Empty falls through to the stored token inside the service.
            'pretalx_token' => $token,
        ]);

        try {
            $probe = $client->probe();
        } catch (RuntimeException $e) {
            Toast::flashDanger('Could not reach pretalx', $e->getMessage());

            return back();
        } catch (Throwable $e) {
            Toast::flashDanger('Could not reach pretalx', $e->getMessage());

            return back();
        }

        $pretalx->rememberEvents($validated['url'], $probe['events']);

        // The URL being tested is usually not saved yet, so the settings page is told
        // which instance to read the remembered list from on the way back.
        session()->flash('pretalx.tested_url', $validated['url']);

        $this->report($probe);

        return back();
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function report(array $probe): void
    {
        $seen = count($probe['events']).' '.str('event')->plural(count($probe['events'])).' visible';

        if ($probe['warning'] !== null) {
            Toast::flashWarning('Connected to pretalx', $probe['warning'].' '.$seen.'.');

            return;
        }

        Toast::flashSuccess(
            'Connected to pretalx',
            $probe['eventName'].': '.$probe['slots'].' '.str('session')->plural($probe['slots'] ?? 0).
            ' in the published schedule. '.$seen.'.',
        );
    }
}
