<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A timeout outlives the moderator who issued it.
 *
 * `issued_by_user_id` was required and cascaded, which made a moderator closing their
 * account delete every timeout they had ever issued, and left a timeout carried across
 * an account deletion with no issuer to name. Chat bans already answer null here for
 * the same reason; timeouts now match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeouts', function (Blueprint $table) {
            $table->dropForeign(['issued_by_user_id']);
        });

        Schema::table('timeouts', function (Blueprint $table) {
            $table->foreignId('issued_by_user_id')->nullable()->change();
        });

        Schema::table('timeouts', function (Blueprint $table) {
            $table->foreign('issued_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timeouts', function (Blueprint $table) {
            $table->dropForeign(['issued_by_user_id']);
        });

        Schema::table('timeouts', function (Blueprint $table) {
            $table->foreignId('issued_by_user_id')->nullable(false)->change();
        });

        Schema::table('timeouts', function (Blueprint $table) {
            $table->foreign('issued_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
