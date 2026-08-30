<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a recording became visible, which is what the notification delay counts from.
 *
 * `updated_at` cannot answer it: processing rewrites a recording constantly, so a
 * thumbnail captured an hour after publishing would push the send another four hours
 * out. Backfilled from `created_at` for what is already published, so an existing
 * archive is past its delay on the first run rather than mailed out in one burst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->index(['is_published', 'published_at']);
        });

        DB::table('recordings')
            ->where('is_published', true)
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'published_at']);
            $table->dropColumn('published_at');
        });
    }
};
