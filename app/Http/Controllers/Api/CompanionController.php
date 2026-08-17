<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Show;
use App\Models\Source;
use App\Services\ShowControlService;
use Illuminate\Http\JsonResponse;

/**
 * The control-surface API: one source per request, named in the path, no session.
 *
 * Every response carries the full status block, so a surface never has to poll after an
 * action to find out what happened - the button feedback updates from the same reply.
 */
class CompanionController extends Controller
{
    public function __construct(private readonly ShowControlService $control) {}

    public function status(Source $source): JsonResponse
    {
        return response()->json($this->payload($source));
    }

    public function start(Source $source): JsonResponse
    {
        $result = $this->control->start($source);

        // 409 rather than 200: nothing queued is the one press an operator needs to see
        // fail, and it is the surface's cue to light the button up.
        return response()->json(
            $this->payload($source, $result),
            $result['ok'] ? 200 : 409,
        );
    }

    public function stop(Source $source): JsonResponse
    {
        $result = $this->control->stop($source);

        return response()->json($this->payload($source, $result));
    }

    /**
     * @param  array{ok: bool, action: string, message: string, show: Show|null}|null  $result
     * @return array<string, mixed>
     */
    private function payload(Source $source, ?array $result = null): array
    {
        $source->refresh();

        $live = $this->control->liveShow($source);
        ['show' => $next, 'reason' => $reason] = $this->control->nextUp($source);

        return [
            'ok' => $result['ok'] ?? true,
            'action' => $result['action'] ?? null,
            'message' => $result['message'] ?? null,
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'slug' => $source->slug,
                'status' => $source->status?->value,
            ],
            'live' => (bool) $live,
            'live_show' => $this->show($live),
            'next_show' => $this->show($next),
            // What a Play press would do right now, so the surface can label the button
            // without repeating the selection rules.
            'next_action' => $live ? 'none' : match ($reason) {
                'current' => 'start_current',
                'next' => 'start_next',
                default => 'none',
            },
            'viewer_count' => $source->activeViewers()->count(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function show(?Show $show): ?array
    {
        if (! $show) {
            return null;
        }

        return [
            'id' => $show->id,
            'title' => $show->title,
            'title_short' => $this->shortTitle($show->title),
            'status' => $show->status,
            'scheduled_start' => $show->scheduled_start?->toIso8601String(),
            'scheduled_end' => $show->scheduled_end?->toIso8601String(),
            'actual_start' => $show->actual_start?->toIso8601String(),
            // Preformatted in the event's timezone. A control surface is often a box in a
            // rack with its own idea of what time it is - Companion in Docker runs on UTC -
            // and the operator has to read the same clock as the schedule.
            'scheduled_start_clock' => $show->scheduled_start?->format('H:i'),
            'scheduled_end_clock' => $show->scheduled_end?->format('H:i'),
            'actual_start_clock' => $show->actual_start?->format('H:i'),
            'auto_mode' => (bool) $show->auto_mode,
            'viewer_count' => $show->viewer_count,
        ];
    }

    /**
     * A title cut to something a 72px key can hold.
     *
     * Programme titles are written for a schedule page - "Panel: The Art of Fursuit
     * Construction, Part Two" is a normal one - and a button that renders the whole thing
     * is a grey smudge. Cutting on a word boundary keeps the part an operator recognises;
     * the full title stays in `title` for anyone who wants it on a bigger display.
     */
    private function shortTitle(string $title): string
    {
        $limit = 26;

        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        $cut = mb_substr($title, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        // Only honour the word boundary if it leaves a useful amount of title. A first
        // word longer than the limit is cut mid-word rather than vanishing.
        if ($lastSpace !== false && $lastSpace >= 14) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B,.;:-").'…';
    }
}
