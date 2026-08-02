<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A role is synced from the identity provider when it carries the identifier
 * that provider uses for it, and is left alone when it does not.
 *
 * This replaces the `assigned_at_login` flag, which said *whether* to sync but
 * never *what to sync against*: the mapping from provider group to role lived in
 * an environment variable, so a new group meant a deploy. The identifier now
 * lives on the role, and an empty one means the role is only ever assigned by
 * hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('slug');
        });

        $this->backfill();

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'assigned_at_login')) {
                // The index has to go first: dropping a column that one covers
                // fails on MySQL.
                try {
                    $table->dropIndex('roles_assigned_at_login_index');
                } catch (\Throwable) {
                    // Older installs never had the index.
                }

                $table->dropColumn('assigned_at_login');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('assigned_at_login')->default(true)->after('slug');
            $table->index('assigned_at_login');
        });

        // Whatever carried an identifier was the set being synced.
        DB::table('roles')->update(['assigned_at_login' => false]);
        DB::table('roles')->whereNotNull('external_id')->update(['assigned_at_login' => true]);

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }

    /**
     * Carry the old mapping over so an existing install keeps syncing without
     * anyone retyping group IDs.
     *
     * Two sources: the OIDC_GROUP_ROLE_MAP pairs, which held the provider group
     * IDs, and the sponsor tiers, which were matched by slug against the
     * registration packages.
     */
    private function backfill(): void
    {
        foreach (explode(',', (string) env('OIDC_GROUP_ROLE_MAP', '')) as $pair) {
            $parts = array_map('trim', explode('=', $pair, 2));

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }

            [$groupId, $slug] = $parts;

            DB::table('roles')
                ->where('slug', $slug)
                ->whereNull('external_id')
                ->update(['external_id' => $groupId]);
        }

        if (! Schema::hasColumn('roles', 'assigned_at_login')) {
            return;
        }

        // Package-derived tiers matched on their own slug, so that is their identifier.
        DB::table('roles')
            ->whereIn('slug', ['sponsor', 'super-sponsor', 'supersponsor'])
            ->where('assigned_at_login', true)
            ->whereNull('external_id')
            ->update(['external_id' => DB::raw('slug')]);
    }
};
