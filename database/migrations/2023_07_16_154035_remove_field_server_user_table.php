<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_user', function (Blueprint $table) {
            // drop client_id
            $table->dropColumn('client_id');
            $table->dropColumn('client');
        });
    }

    public function down(): void
    {
        Schema::table('server_user', function (Blueprint $table) {
            // add client_id
            $table->string('client_id')->nullable();
            $table->string('client')->nullable();
        });
    }
};
