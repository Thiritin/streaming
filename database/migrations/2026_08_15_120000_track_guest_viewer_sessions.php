<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a viewer session stand on its own rather than only through a user row.
 *
 * `source_users` could only describe a signed-in viewer: `user_id` was required, and
 * the edge a session belonged to was read by joining out to `users.server_id`. On an
 * installation with optional login that meant guests were invisible to viewer counts,
 * so every edge reported a load that excluded them - and `HlsController` sent each
 * guest to whichever edge currently reported the lowest number. Nothing ever raised
 * that number, so all guest traffic converged on one edge.
 *
 * Two columns fix both halves:
 *
 *   guest_key  identifies a signed-out viewer across requests. It is a hash of the
 *              session id, never the id itself, so this table cannot be used to
 *              resume anyone's session if it leaks.
 *   server_id  the edge the session is pinned to, recorded on the session rather
 *              than inferred from the user. Guests get stickiness, and the viewer
 *              count becomes a group-by on this table with no join at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_users', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('source_users', function (Blueprint $table) {
            $table->string('guest_key', 64)->nullable()->after('user_id');
            $table->foreignId('server_id')->nullable()->after('guest_key')
                ->constrained('servers')->nullOnDelete();

            $table->index(['source_id', 'guest_key']);
            $table->index(['server_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::table('source_users', function (Blueprint $table) {
            $table->dropIndex(['source_id', 'guest_key']);
            $table->dropIndex(['server_id', 'left_at']);
            $table->dropConstrainedForeignId('server_id');
            $table->dropColumn('guest_key');
        });

        // Guest rows have no user to point at, so they cannot survive the column
        // going back to NOT NULL.
        DB::table('source_users')->whereNull('user_id')->delete();

        Schema::table('source_users', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
