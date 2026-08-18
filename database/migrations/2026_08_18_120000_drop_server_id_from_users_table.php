<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An edge assignment belongs to a viewing session, not to an account.
 *
 * `source_users.server_id` already carried it for signed-out viewers and is what
 * UpdateServerViewerCountsJob reads an edge's load from. Keeping a second copy on
 * `users` meant a scheduled job pinned every account in the database to an edge whether
 * or not it had ever watched anything - 4,076 rows for 10 viewers in a week - and made
 * deprovisioning an edge scale with the size of the user table instead of the number of
 * people on it. `streamkey` stays: it identifies the viewer, not the edge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Server::class);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignIdFor(Server::class)->after('id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
