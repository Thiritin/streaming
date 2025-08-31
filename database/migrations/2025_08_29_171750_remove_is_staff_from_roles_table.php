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
        if (Schema::hasColumn('roles', 'is_staff')) {
            // Drop the index first if it exists
            Schema::table('roles', function (Blueprint $table) {
                try {
                    $table->dropIndex('roles_is_staff_index');
                } catch (\Exception $e) {
                    // Index might not exist
                }
            });
            
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('is_staff');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('assigned_at_login');
        });
    }
};
