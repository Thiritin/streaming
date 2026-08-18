<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-viewer opt-outs for the switchable features.
 *
 * One JSON column rather than a column per feature: a key is only written when
 * a viewer turns something off, so an absent key means "whatever the
 * installation says" and adding a feature needs no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('feature_preferences')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('feature_preferences');
        });
    }
};
