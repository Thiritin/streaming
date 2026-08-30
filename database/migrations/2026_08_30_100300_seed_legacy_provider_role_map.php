<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The convention provider's role mapping, from the roles that already claim a group ID
 * or a package name.
 *
 * `Role.external_id` was the lookup key and stops being one here: it has no notion of
 * which provider said it, so a second provider releasing a group literally named
 * `staff` would have granted this installation's staff role. It survives as the seed
 * for this one map and nothing reads it that way again.
 *
 * Two rules per role, because the old matcher looked in both places: an exact match on
 * the `groups` claim, and a contains match on the registration packages, which read
 * like "day-supersponsor-2026".
 */
return new class extends Migration
{
    public function up(): void
    {
        $provider = DB::table('auth_providers')->where('key', 'identity')->first();

        if ($provider === null || $provider->role_map !== null) {
            return;
        }

        $roles = DB::table('roles')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->get(['id', 'external_id']);

        $map = [];

        foreach ($roles as $role) {
            // The baseline is handed out for having signed in at all, which is what
            // grants_baseline already says. A rule for it would be a second answer.
            if ($role->external_id === 'attendee') {
                continue;
            }

            $map[] = ['claim' => 'groups', 'match' => 'exact', 'value' => $role->external_id, 'role_id' => $role->id];
            $map[] = ['claim' => 'packages', 'match' => 'contains', 'value' => $role->external_id, 'role_id' => $role->id];
        }

        DB::table('auth_providers')->where('id', $provider->id)->update([
            'role_map' => json_encode($map),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('auth_providers')->where('key', 'identity')->update(['role_map' => null]);
    }
};
