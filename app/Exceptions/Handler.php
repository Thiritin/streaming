<?php

namespace App\Exceptions;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Statuses that get the branded Inertia error page instead of Laravel's own view.
     *
     * @var array<int, int>
     */
    private const INERTIA_STATUSES = [403, 404, 429, 500, 503];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    }

    /**
     * Errors come back as an Inertia page so a wrong link lands on the site's own
     * chrome rather than Laravel's stock error view.
     *
     * @param  Request  $request
     */
    public function render($request, Throwable $e): Response
    {
        $response = parent::render($request, $e);

        // A stale CSRF token is not worth a page: bounce back with a message.
        if ($response->getStatusCode() === 419 && ! $request->expectsJson() && $request->hasSession()) {
            return back()->with('message', 'The page expired, please try again.');
        }

        if (! $this->shouldRenderErrorPage($request, $response)) {
            return $response;
        }

        // A 404 for an unmatched URL never reaches the web middleware group, so the
        // shared props the layout needs (branding, auth) are not there yet.
        if (! array_key_exists('branding', Inertia::getShared())) {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));
        }

        return Inertia::render('ErrorPage', ['status' => $response->getStatusCode()])
            ->toResponse($request)
            ->setStatusCode($response->getStatusCode());
    }

    /**
     * JSON callers keep their JSON, and a debug session keeps the stack trace for
     * anything that is actually a crash.
     */
    private function shouldRenderErrorPage(Request $request, Response $response): bool
    {
        if ($request->expectsJson()) {
            return false;
        }

        $status = $response->getStatusCode();

        if (! in_array($status, self::INERTIA_STATUSES, true)) {
            return false;
        }

        return ! (config('app.debug') && $status >= 500);
    }
}
