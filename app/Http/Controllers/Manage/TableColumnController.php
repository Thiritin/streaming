<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Persists which columns an operator has hidden, per table, for the session.
 *
 * Filament kept this state per user too; losing it on every navigation is one of the
 * easiest regressions to ship in a rewrite.
 */
class TableColumnController extends Controller
{
    public function update(Request $request, string $table): RedirectResponse
    {
        $validated = $request->validate([
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string'],
        ]);

        session()->put("manage.table.{$table}.hidden", array_values($validated['hidden']));

        return back();
    }
}
