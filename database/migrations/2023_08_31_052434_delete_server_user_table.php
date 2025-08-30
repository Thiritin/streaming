<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_user', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(\App\Models\Server::class);
            $table->dropConstrainedForeignIdFor(\App\Models\User::class);
            $table->drop();
        });
    }

    public function down(): void
    {
        Schema::create('server_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Server::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(\App\Models\User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('streamkey')->nullable();
        });
    }
};
