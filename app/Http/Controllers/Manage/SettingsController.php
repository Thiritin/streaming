<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Services\PretalxService;
use App\Support\Manage\Settings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * System settings: the identity, login copy, colours and links that make one
 * installation this convention's rather than a generic one.
 *
 * The page is generated from config/settings.php, so a new knob is a config entry and
 * needs nothing here.
 */
class SettingsController extends Controller
{
    public function edit(Settings $settings, PretalxService $pretalx): Response
    {
        $this->authorizeSettings();

        return inertia('Manage/Settings', [
            'groups' => $settings->groups(),
            // Filled by the last successful connection test, so the event slug can be
            // picked rather than typed. Empty until then, and the field stays free text.
            // A test that just ran names the instance it used, which is normally still
            // unsaved at that point.
            'pretalxEvents' => $pretalx->rememberedEvents(session('pretalx.tested_url')),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $this->authorizeSettings();

        $validated = $request->validate(
            $settings->rules() + ['values' => ['required', 'array']],
            [],
            $settings->attributes(),
        );

        $settings->save($validated['values']);

        Toast::flashSuccess('Settings saved', 'The public site picks the change up immediately.');

        return back();
    }

    public function reset(Settings $settings): RedirectResponse
    {
        $this->authorizeSettings();

        $settings->reset();

        Toast::flashSuccess(
            'Settings reset to defaults',
            'Uploaded files are kept in case another setting still points at them.',
        );

        return back();
    }

    /**
     * Changing the login copy and accent colour of the public site is an
     * administrator's job, not every staff member's.
     */
    private function authorizeSettings(): void
    {
        abort_unless(request()->user()?->hasPermission('admin.access'), 403);
    }
}
