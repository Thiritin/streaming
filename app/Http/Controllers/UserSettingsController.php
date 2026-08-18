<?php

namespace App\Http\Controllers;

use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A viewer's own settings. Today that is one thing: switching off the parts of
 * the site they do not want.
 *
 * The list is built from what the installation has switched on, so a viewer is
 * only ever offered features that exist. Anything the installation has off is
 * not shown and not accepted on save either, which keeps the two layers from
 * disagreeing: a viewer can subtract, never add.
 */
class UserSettingsController extends Controller
{
    /**
     * The copy for each switchable feature, in the order it is shown.
     *
     * @var array<string, array{label: string, helper: string}>
     */
    private const COPY = [
        'chat' => [
            'label' => 'Chat',
            'helper' => 'Off hides the chat panel and the pop-out everywhere, and takes emotes with it.',
        ],
        'emotes' => [
            'label' => 'Emotes',
            'helper' => 'Off hides the picker and shows :name: as plain text instead of an image.',
        ],
        'boops' => [
            'label' => 'Boops',
            'helper' => 'The paw under the player and its shared counter.',
        ],
    ];

    public function edit(Request $request): Response
    {
        $effective = Features::forUser($request->user());

        return Inertia::render('Settings', [
            'featureSettings' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => self::COPY[$key]['label'] ?? $key,
                'helper' => self::COPY[$key]['helper'] ?? '',
                'enabled' => $effective[$key] ?? false,
            ], Features::switchableKeys()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $switchable = Features::switchableKeys();

        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['boolean'],
        ]);

        $user = $request->user();
        $preferences = $user->feature_preferences ?? [];

        foreach ($data['features'] as $key => $enabled) {
            // Silently ignore anything the installation does not offer rather
            // than failing the save: a stale tab should not be able to write a
            // preference for a feature that has since been switched off.
            if (! in_array($key, $switchable, true)) {
                continue;
            }

            // On is the default, so it is stored as the absence of a key. That
            // way a feature switched off and back on installation-wide does not
            // leave a row of stale "on" preferences behind.
            if ($enabled) {
                unset($preferences[$key]);

                continue;
            }

            $preferences[$key] = false;
        }

        $user->feature_preferences = $preferences === [] ? null : $preferences;
        $user->save();

        return back()->with('status', 'Settings saved.');
    }
}
