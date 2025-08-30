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
        Schema::table('role_user', function (Blueprint $table) {
            // Drop the columns we don't need
            $table->dropColumn(['assigned_at', 'expires_at']);
            
            // Modify assigned_by to be a foreign key to users
            $table->dropColumn('assigned_by');
        });
        
        Schema::table('role_user', function (Blueprint $table) {
            // Add assigned_by_user_id as a foreign key
            $table->foreignId('assigned_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
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