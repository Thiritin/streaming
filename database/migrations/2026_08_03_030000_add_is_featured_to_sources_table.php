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
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
