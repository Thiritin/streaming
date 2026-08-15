<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the at-most-one-featured-source index.
 *
 * The index itself was well formed - a partial unique index on Postgres and SQLite, a
 * generated NULL-for-unfeatured column plus a unique index on MySQL. What it could not
 * survive is the order the application promotes in.
 *
 * `Source::booted()` demotes the other sources from an *after-write* hook, guarded on
 * `wasChanged('is_featured')`. So promoting a second channel writes `is_featured = true`
 * to the new row while the old row still holds it, and the constraint rejects that UPDATE
 * before the demotion ever runs. Featuring any channel other than the current one was
 * impossible: `UNIQUE constraint failed: sources.is_featured`.
 *
 * Making the constraint work would mean demoting before the write instead of after, or a
 * deferrable constraint - which Postgres supports but MySQL and SQLite do not, so the
 * three drivers would stop agreeing. The invariant is worth less than that: it is a UI
 * affordance, not data integrity, and `Source::featured()` reads `first()` so even a
 * transient double-featured state resolves to one channel rather than erroring.
 *
 * Enforcement stays where it already was, in `Source::booted()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // MySQL has no DROP INDEX IF EXISTS, so ask first. An install that never ran
            // the adding migration, or ran it before this one existed, must not fail here.
            $exists = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.statistics '
                .'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                ['sources', 'sources_featured_unique'],
            );

            if ((int) ($exists->c ?? 0) > 0) {
                DB::statement('DROP INDEX sources_featured_unique ON sources');
            }

            if (Schema::hasColumn('sources', 'featured_marker')) {
                DB::statement('ALTER TABLE sources DROP COLUMN featured_marker');
            }

            return;
        }

        DB::statement('DROP INDEX IF EXISTS sources_featured_unique');
    }

    /**
     * Restores the previous schema, broken constraint included. Rolling back is meant to
     * return the database to what it was, not to a better version of it.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sources ADD COLUMN featured_marker TINYINT GENERATED ALWAYS AS (IF(is_featured, 1, NULL)) STORED');
            DB::statement('CREATE UNIQUE INDEX sources_featured_unique ON sources (featured_marker)');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX sources_featured_unique ON sources (is_featured) WHERE is_featured');
    }
};
