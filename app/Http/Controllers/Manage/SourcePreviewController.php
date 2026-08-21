<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Support\Manage\Status;
use App\Support\SourceProbe;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Watch what an encoder is actually pushing, without going through the public site.
 *
 * The player on the front page only appears for a live show on an online source, which
 * is the wrong gate for the question this page answers: an operator wiring up a stage
 * needs to see the picture before any of that is arranged. The playlist is requested
 * with `preview=1`, which HlsController honours for anyone past `access-manage` - it
 * skips the viewer session row and the edge pin, so checking a source does not show up
 * as a viewer on it. It is not an access bypass: an operator already reaches every
 * source's playlist by being signed in.
 */
class SourcePreviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Source::class);

        $sources = Source::query()
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $selected = $this->selected($request, $sources);

        return inertia('Manage/Sources/Preview', [
            'sources' => $sources
                ->map(fn (Source $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'slug' => $source->slug,
                    'status' => Status::source($source->status),
                ])
                ->all(),
            'selected' => $selected ? $this->payload($selected) : null,
            // Deferred so switching sources renders the player straight away and the
            // edge round trip lands after it, rather than holding the page open for it.
            'probe' => $selected
                ? Inertia::defer(fn () => SourceProbe::run($selected))
                : null,
        ]);
    }

    private function selected(Request $request, $sources): ?Source
    {
        $slug = $request->string('source')->toString();

        return ($slug !== '' ? $sources->firstWhere('slug', $slug) : null) ?? $sources->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Source $source): array
    {
        $live = $source->liveShows()->first();

        return [
            'id' => $source->id,
            'name' => $source->name,
            'slug' => $source->slug,
            'status' => Status::source($source->status),
            'description' => $source->description,
            // The `preview` flag is what keeps this out of the viewer counts; see
            // HlsController::isPreview.
            'hls_url' => $source->getHlsUrl().(str_contains($source->getHlsUrl(), '?') ? '&' : '?').'preview=1',
            'rtmp_url' => $source->getRtmpServerUrl(),
            'edit_url' => route('manage.sources.edit', $source),
            'live_show' => $live ? [
                'title' => $live->title,
                'url' => route('manage.shows.edit', $live),
            ] : null,
            'viewer_count' => $source->shows()->where('status', 'live')->sum('viewer_count'),
        ];
    }
}
