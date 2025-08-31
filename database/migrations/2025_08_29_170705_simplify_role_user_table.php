<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('role_user', 'expires_at')) {
            Schema::table('role_user', function (Blueprint $table) {
                // Drop the index first if it exists
                $table->dropIndex('role_user_expires_at_index');
            });
        }
        
        Schema::table('role_user', function (Blueprint $table) {
            // Drop the columns we don't need if they exist
            $columnsToDelete = [];
            if (Schema::hasColumn('role_user', 'assigned_at')) {
                $columnsToDelete[] = 'assigned_at';
            }
            if (Schema::hasColumn('role_user', 'expires_at')) {
                $columnsToDelete[] = 'expires_at';
            }
            if (Schema::hasColumn('role_user', 'assigned_by')) {
                $columnsToDelete[] = 'assigned_by';
            }
            
            if (!empty($columnsToDelete)) {
                $table->dropColumn($columnsToDelete);
            }
        });

        if (!Schema::hasColumn('role_user', 'assigned_by_user_id')) {
            Schema::table('role_user', function (Blueprint $table) {
                // Add assigned_by_user_id as a foreign key
                $table->foreignId('assigned_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropColumn('assigned_by_user_id');

            // Restore original columns
            $table->datetime('assigned_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->datetime('expires_at')->nullable();
            $table->string('assigned_by')->nullable();
        });
    }
};
