<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Hetzner instance size a server was provisioned as.
 *
 * It used to be hardcoded in CreateVirtualMachineJob - `ccx43` for an origin, `cpx21`
 * for an edge - so the size was a deploy-time decision and nothing recorded what any
 * existing server actually was. Both are worth fixing: Hetzner bills hourly, and the
 * gap between ccx33 and ccx43 across a two-week event is around €70.
 *
 * Nullable on purpose. Servers created before this column existed genuinely do not know
 * their size, and inventing one would be worse than admitting it; readers fall back to
 * the per-role default in config/stream.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('server_type', 32)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('server_type');
        });
    }
};
