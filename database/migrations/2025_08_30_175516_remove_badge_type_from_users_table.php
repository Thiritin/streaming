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
        if (Schema::hasColumn('users', 'badge_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('badge_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('badge_type', [
                'subscriber_yellow',
                'subscriber_purple',
                'moderator',
                'admin',
            ])->nullable();
        });
    }
};
