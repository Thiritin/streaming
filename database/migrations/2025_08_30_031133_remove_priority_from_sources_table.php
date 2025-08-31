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
        if (Schema::hasColumn('sources', 'priority')) {
            // Drop the index first if it exists
            Schema::table('sources', function (Blueprint $table) {
                try {
                    $table->dropIndex('sources_priority_index');
                } catch (\Exception $e) {
                    // Index might not exist
                }
            });
            
            Schema::table('sources', function (Blueprint $table) {
                $table->dropColumn('priority');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->integer('priority')->default(0)->after('is_primary');
        });
    }
};
