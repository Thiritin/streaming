<?php

namespace App\Http\Middleware;

use App\Support\Manage\Navigation;
use App\Support\Manage\Overview;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Props every /manage page needs: the rail and the status strip.
 *
 * Both are top-level props on purpose. Inertia partial reloads address top-level keys,
 * and the status strip polls on its own interval independently of the page body.
 */
class ShareManageProps
{
    public function handle(Request $request, Closure $next)
    {
        Inertia::share([
            'manageNav' => fn () => app(Navigation::class)->groups(),
            'manageStatus' => fn () => app(Overview::class)->statusStrip(),
        ]);

        return $next($request);
    }
}
