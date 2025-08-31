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
        Schema::table('users', function (Blueprint $table) {
            $columnsToRemove = [];
            
            if (Schema::hasColumn('users', 'level')) {
                $columnsToRemove[] = 'level';
            }
            if (Schema::hasColumn('users', 'is_provisioning')) {
                $columnsToRemove[] = 'is_provisioning';
            }
            if (Schema::hasColumn('users', 'timeout_expires_at')) {
                $columnsToRemove[] = 'timeout_expires_at';
            }
            if (Schema::hasColumn('users', 'badge_type')) {
                $columnsToRemove[] = 'badge_type';
            }
            
            if (!empty($columnsToRemove)) {
                $table->dropColumn($columnsToRemove);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('level')->default(1);
            $table->boolean('is_provisioning')->default(false);
            $table->timestamp('timeout_expires_at')->nullable();
            $table->string('badge_type')->nullable();
        });
    }
};
