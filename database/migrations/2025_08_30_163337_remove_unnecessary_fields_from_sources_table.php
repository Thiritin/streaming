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
        // Try to drop index first (for SQLite compatibility)
        try {
            Schema::table('sources', function (Blueprint $table) {
                $table->dropIndex('sources_is_active_index');
            });
        } catch (\Exception $e) {
            // Index doesn't exist, continue
        }
        
        Schema::table('sources', function (Blueprint $table) {
            // Drop columns if they exist
            $columnsToDrop = [];
            foreach (['is_active', 'is_primary', 'rtmp_url', 'hls_url', 'metadata'] as $column) {
                if (Schema::hasColumn('sources', $column)) {
                    $columnsToDrop[] = $column;
                }
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->string('rtmp_url')->nullable();
            $table->string('hls_url')->nullable();
            $table->json('metadata')->nullable();
        });
    }
};