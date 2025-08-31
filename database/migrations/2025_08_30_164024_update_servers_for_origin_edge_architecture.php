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
            // Add fields for origin server
            $table->string('hls_path')->nullable()->after('shared_secret');
            $table->string('origin_url')->nullable()->after('hls_path');
            
            // Add fields for edge server monitoring
            $table->integer('viewer_count')->default(0)->after('max_clients');
            $table->timestamp('last_heartbeat')->nullable()->after('viewer_count');
            
            // Add index for finding origin server quickly
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['hls_path', 'origin_url', 'viewer_count', 'last_heartbeat']);
            $table->dropIndex(['type', 'status']);
        });
    }
};