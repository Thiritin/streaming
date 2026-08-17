<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a server says about itself when it checks in.
 *
 * `ServerProvisionController::heartbeat()` has always written a `metadata` column that
 * no migration ever created, so every heartbeat carrying a health payload answered 500
 * with `Unknown column 'metadata'`. Nobody noticed because no server was sending
 * heartbeats at all: the install script installed a cron entry pointing at a
 * `heartbeat.sh` it never wrote, and `servers.last_heartbeat` only moved because
 * UpdateServerViewerCountsJob was stamping it on the app's own rows.
 *
 * Distinct from `health_status`, which is the app polling `/health` from outside every
 * minute. This is the box's own view, which can differ - a server that can still serve
 * a health check while knowing something is wrong is exactly the case worth recording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('health_check_message');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
