<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('hetzner_id')->nullable()->change();
            $table->string('hostname')->nullable()->change();
            $table->string('ip')->nullable()->change();
            $table->string('status')->default('provisioning')->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('hetzner_id')->nullable(false)->change();
            $table->string('hostname')->nullable(false)->change();
            $table->string('ip')->nullable(false)->change();
            $table->string('status')->default('provisioning')->change();
        });
    }
};
