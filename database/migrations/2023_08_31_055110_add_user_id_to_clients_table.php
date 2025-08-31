<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if clients table exists before trying to modify it
        if (!Schema::hasTable('clients')) {
            return;
        }
        
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\User::class)->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Check if clients table exists before trying to modify it
        if (!Schema::hasTable('clients')) {
            return;
        }
        
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(\App\Models\User::class);
        });
    }
};
