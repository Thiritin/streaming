<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which provider built each server, and what that provider calls it.
 *
 * `hetzner_id` was doing both jobs at once - it was the id and, by being empty, the
 * statement that a server was managed by hand. Backfilled from it, which is the only
 * fact the old schema records, and it is right for every row today.
 *
 * `hetzner_id` is deliberately kept for now: edges already in the field POST against it
 * and the manage table searches it, so both reading sides need a deploy before the
 * column can go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Manual by default: a row nobody claimed is nobody's to delete through an
            // API, and the only paths that build a cloud machine name their provider.
            $table->string('provider', 32)->default('manual')->after('type');
            $table->string('external_id')->nullable()->after('provider');
            $table->index('provider');
        });

        DB::table('servers')->update([
            'provider' => DB::raw("CASE WHEN hetzner_id IS NULL OR hetzner_id = '' THEN 'manual' ELSE 'hetzner' END"),
            'external_id' => DB::raw('hetzner_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn(['provider', 'external_id']);
        });
    }
};
