<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a server was last refused.
 *
 * A box whose credential no longer matches keeps checking in every minute and keeps
 * being turned away, and until now that presented exactly like a crashed box or a dead
 * network: a stale heartbeat. The app is the side answering 401, so it is the side that
 * can tell the two apart. Stamped on the first refusal, cleared by the first request
 * that authenticates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('credential_rejected_at')->nullable()->after('deploy_token_rotated_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('credential_rejected_at');
        });
    }
};
