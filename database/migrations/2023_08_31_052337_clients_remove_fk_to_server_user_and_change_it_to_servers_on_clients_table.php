<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        \App\Models\Client::truncate();
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign('server_user_id_fk');
            $table->dropColumn('server_user_id');
            $table->foreignIdFor(\App\Models\Server::class)->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(\App\Models\Server::class);
            $table->foreignId('server_user_id')->nullable()->after('id')->constrained('server_user','id','server_user_id_fk')->cascadeOnDelete();
        });
    }
};
