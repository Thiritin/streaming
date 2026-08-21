<?php

namespace App\Http\Controllers;

use App\Enum\PlaybackTokenTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Models\DisplayScreen;
use App\Models\EmbedKey;
use App\Models\Source;
use App\Services\PlaybackTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unattended displays and external players.
 *
 * A short code is handed out, presented either as `/d/{code}` or typed into the
 * box at `/d`. Either way it is exchanged for a session, after which the hub lets
 * whoever set the screen up choose a source, start a chrome-free fullscreen
 * player, or copy a URL into VLC. Nothing here needs an account.
 *
 * The key never reaches an edge. It authorises against this app, which then mints
 * ordinary per-source tokens, so revoking a key takes effect on the next playlist
 * refresh rather than needing an allowlist pushed to every edge.
 */
class DisplayController extends Controller
{
    private const SESSION_KEY = 'display_key_id';

    /**
     * When this screen presented its code. Compared against the key's own
     * `signed_out_at`, which is how /manage signs screens out from a distance.
     */
    private const SESSION_SINCE = 'display_key_since';

    /**
     * The code box. Where an unset screen lands, and where a code goes when the
     * address bar is the awkward place to type it.
     */
    public function prompt(Request $request): Response|RedirectResponse
    {
        if ($this->currentKey($request)) {
            return to_route('display.hub');
        }

        return Inertia::render('Display/Enter');
    }

    /**
     * Exchange the code in `/d/{key}` for a session, then redirect to the hub.
     *
     * The redirect is the point: a display sits in a public place with its address
     * bar visible, and the key would otherwise stay in the URL, in history, and in
     * any screenshot of the screen.
     */
    public function enter(Request $request, string $key): RedirectResponse
    {
        return $this->openSession($request, $key)
            ?? to_route('display.prompt')->withErrors(['code' => 'That code is not valid.']);
    }

    /**
     * The same exchange from the form.
     */
    public function redeem(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        return $this->openSession($request, $validated['code'])
            ?? back()->withErrors(['code' => 'That code is not valid.']);
    }

    public function hub(Request $request): Response|RedirectResponse
    {
        $key = $this->currentKey($request);

        if (! $key) {
            return to_route('display.prompt');
        }

        $screen = DisplayScreen::report($key, $request, 'hub', null);

        return Inertia::render('Display/Hub', [
            'keyName' => $key->name,
            'screenName' => $screen->displayName(),
            'sources' => $this->sources($key),
            'featuredSlug' => Source::featured()?->slug,
            'directedSlug' => $screen->directedSource?->slug,
        ]);
    }

    /**
     * The kiosk itself: no chrome, autoplay muted, switchable.
     */
    public function play(Request $request): Response|RedirectResponse
    {
        $key = $this->currentKey($request);

        if (! $key) {
            return to_route('display.prompt');
        }

        $sources = $this->sources($key);
        $screen = DisplayScreen::report($key, $request, 'play', null);

        /*
         * A standing instruction outranks the query string: the screen may be coming
         * back up after a reboot with the channel it was launched on still in its
         * URL, and what /manage last asked for is the newer answer.
         */
        $initial = $this->initialSlug(
            $sources,
            $screen->directedSource?->slug ?? $request->query('source'),
        );

        return Inertia::render('Display/Play', [
            'sources' => $sources,
            'initialSlug' => $initial,
        ]);
    }

    /**
     * Fresh source list and playback URLs, polled by both pages.
     *
     * Sources go on and off air while a screen is unattended for days, and the
     * embed tokens have no expiry, so this is only about status: the page swaps
     * channel off the back of it without a reload.
     */
    public function state(Request $request)
    {
        $key = $this->currentKey($request);

        if (! $key) {
            return response()->json(['error' => 'Not authorised'], 401);
        }

        $sources = $this->sources($key);

        $page = $request->query('page') === 'play' ? 'play' : 'hub';
        $playing = $page === 'play'
            ? Source::where('slug', (string) $request->query('source'))->first()
            : null;

        $screen = DisplayScreen::report($key, $request, $page, $playing);

        return response()->json([
            'sources' => $sources,
            'featuredSlug' => Source::featured()?->slug,
            // Null once the screen reports it arrived, so somebody standing at the
            // screen can still switch channel afterwards.
            'directedSlug' => $screen->directedSource?->slug,
        ]);
    }

    /**
     * Sign this screen out.
     *
     * The whole session goes, not just the key id: it is the only credential the
     * screen holds, and whoever hands the display back should not be handing over
     * anything that can be replayed.
     */
    public function leave(Request $request): RedirectResponse
    {
        DisplayScreen::forSession($request)?->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('display.prompt');
    }

    /**
     * Turn a presented code into a session, or null if it is not a code.
     *
     * The id rotates on the way in: the session this mints is what stands in for
     * the credential from here on, and a screen in a corridor is exactly the place
     * where someone could have handed it a session id first.
     */
    private function openSession(Request $request, string $presented): ?RedirectResponse
    {
        $key = EmbedKey::findByKey($presented);

        if (! $key) {
            return null;
        }

        $key->touchUsage($request);

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $key->id);
        $request->session()->put(self::SESSION_SINCE, now()->getTimestamp());

        return to_route('display.hub');
    }

    private function currentKey(Request $request): ?EmbedKey
    {
        $id = $request->session()->get(self::SESSION_KEY);

        // Looked up every request rather than trusted from the session, so deleting
        // the row locks the screen out on its next page load.
        $key = $id ? EmbedKey::find($id) : null;

        if ($key && ! $key->acceptsSessionFrom($request->session()->get(self::SESSION_SINCE))) {
            $request->session()->forget([self::SESSION_KEY, self::SESSION_SINCE]);

            return null;
        }

        return $key;
    }

    /**
     * Every source, each with a token bound to it.
     *
     * A key is not scoped to a subset, so the entitlement decision is "is this a
     * display" rather than "which channel". The token is still per source, because
     * the `src` claim is what an edge checks; a wildcard would mean changing the
     * verifier.
     *
     * A channel with no live show on it is listed but carries no token and no URLs.
     * Screens sit in public corridors and stay on whatever they were left on for
     * days, which is exactly how a hall camera running through setup ends up on a
     * wall; the show is what opens the channel, here as everywhere else. Listing it
     * anyway is deliberate - somebody standing at the screen can see the channel
     * exists and is simply not on air yet, rather than watching it vanish.
     */
    private function sources(EmbedKey $key): array
    {
        $tokens = app(PlaybackTokenService::class);
        $configured = $tokens->isConfigured(PlaybackTokenTypeEnum::EMBED);

        return Source::query()
            ->withLiveShowCount()
            ->orderByDesc('is_featured')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get()
            ->map(function (Source $source) use ($key, $tokens, $configured) {
                $available = $source->isPlayable();

                $token = $configured && $available
                    ? $tokens->issueEmbed(keyId: (string) $key->id, source: $source)
                    : null;

                $master = $token
                    ? route('hls.master', ['stream' => $source->slug]).'?t='.$token
                    : null;

                return [
                    'slug' => $source->slug,
                    'name' => $source->name,
                    'description' => $source->description,
                    'isFeatured' => (bool) $source->is_featured,
                    'isOnline' => $source->status === SourceStatusEnum::ONLINE,
                    // Whether a show is on. Separate from isOnline, which is only
                    // whether the feed is arriving.
                    'isAvailable' => $available,
                    'status' => $source->status?->value,
                    'url' => $master,
                    // VLC follows the master fine, but a fixed rendition is the
                    // reliable choice for players that handle ABR switching badly.
                    'renditions' => $token ? [
                        'fhd' => route('hls.variant', ['variant' => $source->slug.'_fhd']).'?t='.$token,
                        'hd' => route('hls.variant', ['variant' => $source->slug.'_hd']).'?t='.$token,
                        'sd' => route('hls.variant', ['variant' => $source->slug.'_sd']).'?t='.$token,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Featured first, then any online source, then whatever exists.
     *
     * An explicit `?source=` wins, so the hub can start a screen on a given channel
     * and the kiosk can be reloaded onto the same one.
     */
    private function initialSlug(array $sources, ?string $requested): ?string
    {
        $bySlug = collect($sources)->keyBy('slug');

        if ($requested && $bySlug->has($requested)) {
            return $requested;
        }

        $featured = $bySlug->firstWhere('isFeatured', true);

        // On air means both: a show is running and the feed is arriving. A channel
        // that is only ingesting is not something to start a screen on.
        if ($featured && $featured['isOnline'] && $featured['isAvailable']) {
            return $featured['slug'];
        }

        $onAir = $bySlug->where('isOnline', true)->where('isAvailable', true);

        if ($onAir->isNotEmpty()) {
            return $onAir->random()['slug'];
        }

        return $featured['slug'] ?? $bySlug->keys()->first();
    }
}
