<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Make hetzner_id nullable
            $table->string('hetzner_id')->nullable()->change();
            
            // Make ip nullable
            $table->string('ip')->nullable()->change();
            
            // Add health check fields if they don't exist
            if (!Schema::hasColumn('servers', 'health_status')) {
                $table->enum('health_status', ['healthy', 'unhealthy', 'unknown'])->default('unknown')->after('status');
            }
            if (!Schema::hasColumn('servers', 'last_health_check')) {
                $table->timestamp('last_health_check')->nullable()->after('last_heartbeat');
            }
            if (!Schema::hasColumn('servers', 'health_check_message')) {
                $table->string('health_check_message')->nullable()->after('last_health_check');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Revert nullable changes
            $table->string('hetzner_id')->nullable(false)->change();
            $table->string('ip')->nullable(false)->change();
            
            // Drop health check columns
            $table->dropColumn(['health_status', 'last_health_check', 'health_check_message']);
        });
    }
};
