<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\BrandingSetting;
use App\Services\PretalxService;
use App\Services\Telegram\TelegramClient;
use App\Support\AuthModes;
use App\Support\Manage\Settings;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * System settings: the identity, login copy, colours and links that make one
 * installation this convention's rather than a generic one.
 *
 * One registry group is one pane with one URL. Generated from config/settings.php, so
 * a new knob is a config entry and a new pane is a new group. A pane posts only its
 * own fields, so validation is scoped to its group; Settings::save() ignores keys it
 * was not sent.
 */
class SettingsController extends Controller
{
    public function edit(Settings $settings, PretalxService $pretalx, ?string $group = null): Response|RedirectResponse
    {
        $this->authorizeSettings();

        // The bare /manage/settings is the first pane rather than a redirect.
        $key = $group ?? $settings->firstGroupKey();
        $resolved = $key === null ? null : $settings->group($key);

        // A pane that was merged into another one keeps answering, because its URL is
        // printed in the admin docs and pasted between operators.
        if ($resolved === null && $key !== null && ($moved = $settings->movedTo($key))) {
            return redirect()->route('manage.settings.group', $moved, 301);
        }

        abort_if($resolved === null, 404);

        return inertia('Manage/Settings', [
            'group' => $resolved,
            'navigation' => $settings->navigation(),
            // Filled by the last successful connection test, so the event slug can be
            // picked rather than typed. Empty until then, and the field stays free text.
            // A test that just ran names the instance it used, which is normally still
            // unsaved at that point.
            'pretalxEvents' => $pretalx->rememberedEvents(session('pretalx.tested_url')),
            // The providers are rows on a page of their own; the sign-in pane only
            // points at it, so the list itself never reaches this page.
            'providersUrl' => route('manage.providers.index'),
        ]);
    }

    public function update(Request $request, Settings $settings, string $group): RedirectResponse
    {
        $this->authorizeSettings();

        abort_if($settings->group($group) === null, 404);

        $validated = $request->validate(
            $settings->rules($group) + ['values' => ['required', 'array']],
            [],
            $settings->attributes($group),
        );

        /*
         * The sign-in modes are checked and written under one lock. Two administrators
         * saving at once - one switching the provider off, one switching password
         * accounts off - each passed their own check against a state the other was
         * about to change, and the installation ended with no way in.
         */
        if (AuthModes::ownsPane($group)) {
            DB::transaction(function () use ($settings, $validated) {
                BrandingSetting::whereIn('key', AuthModes::settingKeys())->lockForUpdate()->get();

                if ($errors = AuthModes::lockoutErrors($validated['values'])) {
                    throw ValidationException::withMessages($errors);
                }

                $settings->save($validated['values']);
            });

            Toast::flashSuccess('Settings saved', 'The public site picks the change up immediately.');

            return back();
        }

        $settings->save($validated['values']);

        // The bot token moved onto the Notifications pane when Telegram merged into it;
        // saving it still has to tell Telegram where to deliver, or the buttons in a
        // chat do nothing.
        if (array_key_exists('telegram_bot_token', $validated['values'])) {
            $this->syncTelegramWebhook();

            return back();
        }

        Toast::flashSuccess('Settings saved', 'The public site picks the change up immediately.');

        return back();
    }

    /**
     * A token is only half of a working bot: Telegram has to be told where to deliver
     * updates, or the buttons in a chat do nothing at all. Doing it on save means an
     * operator never has to know that setWebhook exists.
     */
    private function syncTelegramWebhook(): void
    {
        Cache::forget('telegram_bot_status');

        $client = app(TelegramClient::class);

        if (! $client->enabled()) {
            Toast::flashSuccess('Settings saved', 'No bot token, so the bot is off and its webhook is closed.');

            return;
        }

        $result = $client->registerWebhook();

        if ($result['ok'] ?? false) {
            $me = $client->me();

            Toast::flashSuccess(
                'Settings saved',
                'Webhook registered'.(isset($me['username']) ? ' for @'.$me['username'] : '').'.',
            );

            return;
        }

        Toast::flashDanger(
            'Saved, but Telegram refused the webhook',
            (string) ($result['description'] ?? 'Check the token and that this site is reachable from the internet.'),
        );
    }

    public function reset(Settings $settings): RedirectResponse
    {
        $this->authorizeSettings();

        if ($reason = AuthModes::resetLockout()) {
            Toast::flashDanger('Settings not reset', $reason);

            return back();
        }

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
