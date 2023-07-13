<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('server_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Server::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\User::class)->constrained()->cascadeOnDelete();
            $table->string('ip');
            $table->timestamp('start');
            $table->timestamp('stop')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_user');
    }
};
