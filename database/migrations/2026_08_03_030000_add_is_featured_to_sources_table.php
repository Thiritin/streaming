<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit flag for the featured channel.
 *
 * "Featured" was inferred from `priority`: whichever source sorted first was treated as
 * the main channel, by the stage hero and by what an ended show promotes. That conflates
 * two different decisions. Priority is display order on the schedule grid, and reordering
 * the grid silently moved which channel the whole site promoted.
 *
 * The flag is single-valued, enforced in Source::booted(): promoting one channel demotes
 * the rest, so there is never an ambiguous "which one is featured".
 *
 * Database constraint: MySQL 8.0 (production) has no partial/filtered unique index, so it
 * gets a generated column that is NULL for non-featured rows (NULLs don't collide in a
 * unique index) plus a unique index on that column. PostgreSQL (local dev) and SQLite
 * (tests) both support partial unique indexes directly, so they use that instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('priority');
        });

        // Preserve current behaviour for existing installs: whatever priority ordering
        // was already promoting stays promoted, so this is not a behaviour change on
        // deploy. `ordered()` is priority desc, then name.
        $current = DB::table('sources')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->first();

        if ($current) {
            DB::table('sources')->where('id', $current->id)->update(['is_featured' => true]);
        }

        // Add a database constraint for at-most-one featured source, backing up the
        // application-level enforcement in Source::booted().
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sources ADD COLUMN featured_marker TINYINT GENERATED ALWAYS AS (IF(is_featured, 1, NULL)) STORED');
            DB::statement('CREATE UNIQUE INDEX sources_featured_unique ON sources (featured_marker)');
        } else {
            DB::statement('CREATE UNIQUE INDEX sources_featured_unique ON sources (is_featured) WHERE is_featured');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX sources_featured_unique ON sources');
            DB::statement('ALTER TABLE sources DROP COLUMN featured_marker');
        } else {
            DB::statement('DROP INDEX sources_featured_unique');
        }

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
