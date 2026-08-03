<?php

namespace App\Services;

use App\Models\BrandingSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for a pretalx instance.
 *
 * pretalx owns the programme; this pulls the published schedule so a slot can be turned
 * into a show. Nothing is ever written back: the API has no write capabilities, and a
 * slot's live state (running late, overrunning) simply does not exist there, so the
 * imported times are planned times and stay ours to adjust afterwards.
 *
 * Connection details come from the settings table (edited at /manage > Settings) and fall
 * back to config/pretalx.php.
 */
class PretalxService
{
    /**
     * Short enough that a schedule release shows up on the next visit, long enough that
     * ticking through the import screen is not one request per click.
     */
    private const CACHE_TTL = 300;

    /**
     * A published schedule is a few hundred slots; the cap is only there so a runaway
     * `next` link cannot walk forever.
     */
    private const MAX_PAGES = 25;

    private const PAGE_SIZE = 100;

    /**
     * Values that win over the stored settings, for testing a connection that has not
     * been saved yet.
     *
     * @var array<string, string|null>
     */
    private array $overrides = [];

    /**
     * A copy pointed at the given connection details instead of the saved ones. Blank
     * values fall through to what is stored, which is how the settings page can test a
     * new URL against the token already in the database.
     *
     * @param  array<string, string|null>  $overrides
     */
    public function using(array $overrides): self
    {
        $clone = clone $this;
        $clone->overrides = array_filter(
            $overrides,
            fn ($value) => is_string($value) && trim($value) !== '',
        );

        return $clone;
    }

    public function baseUrl(): ?string
    {
        $url = trim((string) $this->setting('pretalx_url'));

        return $url === '' ? null : rtrim($url, '/');
    }

    public function event(): ?string
    {
        $event = trim((string) $this->setting('pretalx_event'));

        return $event === '' ? null : $event;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->event() !== null;
    }

    /**
     * Every event on the instance the credentials can see, newest first.
     *
     * Not cached and not tied to the configured event: this is what the settings page
     * asks for when it wants a list of slugs to choose from.
     *
     * @return array<int, array{slug: string, name: string, date_from: ?string, date_to: ?string}>
     */
    public function events(): array
    {
        $base = $this->baseUrl();

        if ($base === null) {
            throw new RuntimeException('No pretalx instance URL. Fill it in before testing the connection.');
        }

        $body = $this->get($base.'/api/events/', ['page_size' => self::PAGE_SIZE]);

        // The events endpoint is a plain list on some versions and paginated on others.
        $events = array_is_list($body) ? $body : ($body['results'] ?? []);

        $events = array_map(fn (array $event) => [
            'slug' => (string) ($event['slug'] ?? ''),
            'name' => $this->text($event['name'] ?? null) ?: (string) ($event['slug'] ?? ''),
            'date_from' => $event['date_from'] ?? null,
            'date_to' => $event['date_to'] ?? null,
        ], $events);

        $events = array_values(array_filter($events, fn (array $event) => $event['slug'] !== ''));

        usort($events, fn (array $a, array $b) => [$b['date_from'], $a['name']] <=> [$a['date_from'], $b['name']]);

        return $events;
    }

    /**
     * Keep the last successful event list for an instance, so the settings page can offer
     * the slugs as a dropdown without going out to the network on every visit.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function rememberEvents(string $instanceUrl, array $events): void
    {
        Cache::put($this->eventsKey($instanceUrl), $events, now()->addDay());
    }

    /**
     * The remembered event list for an instance, empty until a connection test ran.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rememberedEvents(?string $instanceUrl = null): array
    {
        $instanceUrl ??= $this->baseUrl();

        if ($instanceUrl === null) {
            return [];
        }

        return Cache::get($this->eventsKey($instanceUrl), []);
    }

    private function eventsKey(string $instanceUrl): string
    {
        return 'pretalx.events.'.md5(rtrim($instanceUrl, '/'));
    }

    /**
     * Check the connection: what the credentials can see, and whether the configured
     * event has a schedule worth importing.
     *
     * Uncached on purpose - the point is to find out what is true right now.
     *
     * @return array{
     *     events: array<int, array<string, mixed>>, event: ?string,
     *     eventName: ?string, slots: ?int, warning: ?string
     * }
     */
    public function probe(): array
    {
        $events = $this->events();
        $event = $this->event();

        $result = [
            'events' => $events,
            'event' => $event,
            'eventName' => null,
            'slots' => null,
            'warning' => null,
        ];

        if ($event === null) {
            $result['warning'] = 'No event chosen yet.';

            return $result;
        }

        $known = collect($events)->firstWhere('slug', $event);

        if ($known === null) {
            $result['warning'] = "The instance answered, but it has no event '{$event}' these credentials can see.";

            return $result;
        }

        $result['eventName'] = $known['name'];

        // One row is enough to learn whether a published schedule exists at all.
        $slots = $this->get($this->url('slots'), ['page_size' => 1]);
        $result['slots'] = array_key_exists('count', $slots) ? (int) $slots['count'] : null;

        if ($result['slots'] === 0) {
            $result['warning'] = 'That event has no sessions in a published schedule yet.';
        }

        return $result;
    }

    /**
     * Rooms of the configured event, in pretalx's own order.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function rooms(): array
    {
        return $this->cached('rooms', function () {
            $rooms = array_map(fn (array $room) => [
                'id' => (int) $room['id'],
                'name' => $this->text($room['name'] ?? null) ?: 'Room '.$room['id'],
                'position' => $room['position'] ?? PHP_INT_MAX,
            ], $this->pages('rooms'));

            usort($rooms, fn (array $a, array $b) => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);

            return array_map(fn (array $room) => ['id' => $room['id'], 'name' => $room['name']], $rooms);
        });
    }

    /**
     * Slots of the latest published schedule, normalised and ordered by start time.
     *
     * Slots that are not actually scheduled (no room, no start) are dropped: there is
     * nothing to put on a channel timeline.
     *
     * @return array<int, array{
     *     id: string, code: ?string, title: string, description: ?string,
     *     room_id: ?int, speakers: array<int, string>, start: Carbon, end: Carbon
     * }>
     */
    public function slots(): array
    {
        $slots = $this->cached('slots', function () {
            $rows = [];

            foreach ($this->pages('slots', ['expand' => 'submission,submission.speakers']) as $slot) {
                $row = $this->slot($slot);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        });

        // Carbon does not survive the cache as an object, so the times are rehydrated here
        // rather than stored.
        $slots = array_map(function (array $slot) {
            $slot['start'] = Carbon::parse($slot['start']);
            $slot['end'] = Carbon::parse($slot['end']);

            return $slot;
        }, $slots);

        usort($slots, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $slots;
    }

    /**
     * Drop the cached schedule, for when a new version was released mid-event.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey('rooms'));
        Cache::forget($this->cacheKey('slots'));
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|null
     */
    private function slot(array $slot): ?array
    {
        $start = $slot['start'] ?? null;
        $end = $slot['end'] ?? null;
        $room = $slot['room'] ?? null;

        if (! $start || ! $end || $room === null) {
            return null;
        }

        // `room` is an id unless expanded; `submission` is a code unless expanded.
        $submission = is_array($slot['submission'] ?? null) ? $slot['submission'] : [];

        $title = trim((string) ($submission['title'] ?? '')) ?: $this->text($slot['description'] ?? null);

        return [
            'id' => (string) $slot['id'],
            'code' => is_array($slot['submission'] ?? null)
                ? ($submission['code'] ?? null)
                : ($slot['submission'] ?? null),
            'title' => $title ?: 'Untitled session',
            'description' => $this->description($submission) ?: $this->text($slot['description'] ?? null),
            'room_id' => (int) (is_array($room) ? ($room['id'] ?? 0) : $room),
            'speakers' => $this->speakers($submission),
            'start' => Carbon::parse($start)->toIso8601String(),
            'end' => Carbon::parse($end)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $submission
     */
    private function description(array $submission): ?string
    {
        foreach (['abstract', 'description'] as $key) {
            $value = trim((string) ($submission[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Speaker names when the submission was expanded, plain codes otherwise.
     *
     * @param  array<string, mixed>  $submission
     * @return array<int, string>
     */
    private function speakers(array $submission): array
    {
        $speakers = $submission['speakers'] ?? [];

        if (! is_array($speakers)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($speaker) => is_array($speaker)
                ? trim((string) ($speaker['name'] ?? ''))
                : trim((string) $speaker),
            $speakers,
        )));
    }

    /**
     * Every page of a list endpoint, following `next` rather than counting.
     *
     * @param  array<string, string>  $query
     * @return array<int, array<string, mixed>>
     */
    private function pages(string $resource, array $query = []): array
    {
        $url = $this->url($resource);
        $query = $query + ['page_size' => self::PAGE_SIZE];
        $results = [];
        $seen = [];

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            // A `next` link already carries its own query string, including the expands.
            $body = $this->get($url, $page === 0 ? $query : []);

            foreach ($body['results'] ?? [] as $item) {
                $results[] = $item;
            }

            $seen[$url] = true;
            $url = $body['next'] ?? null;

            // A pagination link that points at a page already read would loop forever and
            // pile up duplicates; stopping is better than reading the same page 25 times.
            if ($url !== null && isset($seen[$url])) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        $token = trim((string) $this->setting('pretalx_token'));

        $request = Http::timeout(15)
            ->acceptJson()
            ->when($token !== '', fn ($request) => $request->withHeaders([
                'Authorization' => 'Token '.$token,
            ]));

        try {
            // Passing an empty array as the second argument replaces the URL's own query
            // string with nothing, which would strip the `page` and `expand` of a `next`
            // link and walk page one forever.
            $response = $query === [] ? $request->get($url) : $request->get($url, $query);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not reach pretalx: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(
                'pretalx refused the request. Check the API token, and that the schedule is published.'
            );
        }

        if ($response->status() === 404) {
            throw new RuntimeException('pretalx does not know this event slug, or it has no published schedule.');
        }

        if ($response->failed()) {
            throw new RuntimeException('pretalx answered with HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('pretalx answered with something that is not JSON.');
        }

        return $body;
    }

    private function url(string $resource): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('pretalx is not configured. Set the instance URL and event slug in Settings.');
        }

        return $this->baseUrl().'/api/events/'.rawurlencode($this->event()).'/'.$resource.'/';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function cached(string $resource, callable $callback): mixed
    {
        return Cache::remember($this->cacheKey($resource), self::CACHE_TTL, $callback);
    }

    private function cacheKey(string $resource): string
    {
        return 'pretalx.'.$resource.'.'.md5((string) $this->baseUrl().'|'.(string) $this->event());
    }

    /**
     * pretalx returns internationalised strings as {locale: text}; English first, then
     * whatever else is there, so a single-language instance still reads.
     */
    private function text(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) ?: null;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $preferred = $value['en'] ?? null;
        $text = trim((string) ($preferred ?? reset($value)));

        return $text ?: null;
    }

    private function setting(string $key): mixed
    {
        return $this->overrides[$key] ?? BrandingSetting::getValue($key, config('pretalx.'.$key));
    }
}
