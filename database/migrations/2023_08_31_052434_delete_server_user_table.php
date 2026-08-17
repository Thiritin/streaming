<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_user', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Server::class);
            $table->dropConstrainedForeignIdFor(User::class);
            $table->drop();
        });
    }

    public function down(): void
    {
        Schema::create('server_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('streamkey')->nullable();
        });
    }
};
