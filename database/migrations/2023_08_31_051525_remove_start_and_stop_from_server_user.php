<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_user', function (Blueprint $table) {
            $table->dropColumn(['start', 'stop']);
        });
    }

    public function down(): void
    {
        Schema::table('server_user', function (Blueprint $table) {
            $table->timestamp('start')->nullable();
            $table->timestamp('stop')->nullable();
        });
    }
};
