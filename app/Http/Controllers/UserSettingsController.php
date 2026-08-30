<?php

namespace App\Http\Controllers;

use App\Support\Auth\ConnectionProps;
use App\Support\Features;
use App\Support\ViewerNotificationProps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A viewer's own settings: switching off the parts of the site they do not want, and
 * choosing what they are sent and where.
 *
 * The feature list is built from what the installation has switched on, so a viewer is
 * only ever offered features that exist. Anything the installation has off is not shown
 * and not accepted on save either, which keeps the two layers from disagreeing: a
 * viewer can subtract, never add.
 *
 * Notifications are the same shape one level down: the transports offered are the ones
 * this account can actually be reached on, so an installation whose identity provider
 * releases no email address offers Telegram alone rather than a box that quietly never
 * delivers.
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

    public function edit(Request $request, ?string $section = null): Response
    {
        $sections = $this->sections($request);

        $section ??= array_key_first($sections);

        // A section that does not exist for this installation is a 404 rather than a
        // silent fallback: the URL is shareable, and quietly showing something else is
        // how somebody ends up changing the wrong thing.
        abort_unless(isset($sections[$section]), 404);

        $effective = Features::forUser($request->user());

        return Inertia::render('Settings', [
            'section' => $section,
            'navigation' => array_values(array_map(fn (array $entry) => $entry + [
                'url' => route('settings.edit', $entry['key'] === array_key_first($sections) ? [] : $entry['key']),
            ], $sections)),
            'featureSettings' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => self::COPY[$key]['label'] ?? $key,
                'helper' => self::COPY[$key]['helper'] ?? '',
                'enabled' => $effective[$key] ?? false,
            ], Features::switchableKeys()),
            'notifications' => ViewerNotificationProps::for($request->user()),
            'connections' => ConnectionProps::for($request->user()),
            'account' => [
                'name' => $request->user()->name,
            ],
        ]);
    }

    /**
     * The pages in the left-hand menu, in order.
     *
     * Built from what this installation actually has, so a viewer is never offered an
     * empty page. The first entry is the bare /settings URL; the rest carry their key,
     * which keeps every page linkable.
     *
     * @return array<string, array{key: string, label: string, icon: string}>
     */
    private function sections(Request $request): array
    {
        $sections = [];

        if (Features::enabled('notifications')) {
            $sections['notifications'] = [
                'key' => 'notifications',
                'label' => 'Notifications',
                'icon' => 'bell',
            ];
        }

        if (Features::switchableKeys() !== []) {
            $sections['features'] = [
                'key' => 'features',
                'label' => 'Features',
                'icon' => 'sliders',
            ];
        }

        /*
         * Offered when there is something to connect, or something already connected
         * to look at - the second so a provider an administrator has since switched off
         * can still be disconnected.
         */
        if (ConnectionProps::availableTo($request->user())) {
            $sections['connections'] = [
                'key' => 'connections',
                'label' => 'Connections',
                'icon' => 'key',
            ];
        }

        // Always last, and always there: taking a copy of an account and closing it
        // are not something an installation switches off.
        $sections['account'] = [
            'key' => 'account',
            'label' => 'Account',
            'icon' => 'user',
        ];

        return $sections;
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
