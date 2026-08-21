<?php

namespace App\Http\Controllers;

use App\Models\FeedbackReport;
use App\Models\Show;
use App\Support\Diagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where the Feedback button in the top bar and "Report a problem" on the player
 * both post.
 *
 * Guests included, deliberately: the viewer whose stream is broken is the one least
 * likely to have signed in, and a report that has to be authenticated is a report we
 * do not get. The throttle on the route is what bounds that.
 */
class FeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        /*
         * Telegram handles arrive as `@name`, `name` or a t.me link. Folded onto one
         * shape before validation rather than after, so the rule has a single form to
         * describe and the error message matches what the field actually rejects.
         */
        $request->merge([
            'telegram' => FeedbackReport::normalizeTelegram($request->string('telegram')->toString()),
        ]);

        $validated = $request->validate([
            'type' => ['required', Rule::in([FeedbackReport::TYPE_FEEDBACK, FeedbackReport::TYPE_ISSUE])],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            /*
             * Telegram's own rule: 5 to 32 of letters, digits and underscores. The
             * leading @ and a t.me/ wrapper are stripped before this runs, so all
             * three of the ways somebody writes their handle are accepted.
             */
            'telegram' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]{5,32}$/'],
            'show_slug' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'diagnostics' => ['nullable', 'array'],
        ], [
            'telegram.regex' => 'That does not look like a Telegram username. 5 to 32 letters, digits or underscores.',
        ]);

        $show = isset($validated['show_slug'])
            ? Show::where('slug', $validated['show_slug'])->first()
            : null;

        FeedbackReport::create([
            'type' => $validated['type'],
            'status' => FeedbackReport::STATUS_NEW,
            'user_id' => $request->user()?->id,
            'telegram' => $validated['telegram'] ?? null,
            'message' => $validated['message'],
            'show_id' => $show?->id,
            'source_id' => $show?->source_id,
            'url' => $validated['url'] ?? $request->headers->get('referer'),
            // From the request rather than the payload: the client reports its own
            // string too, and this is the one that cannot be edited.
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            'ip' => $request->ip(),
            'diagnostics' => Diagnostics::sanitize($validated['diagnostics'] ?? []),
        ]);

        return back()->with('status', $validated['type'] === FeedbackReport::TYPE_ISSUE
            ? 'Thanks - your report is with the stream team.'
            : 'Thanks for the feedback.');
    }
}
