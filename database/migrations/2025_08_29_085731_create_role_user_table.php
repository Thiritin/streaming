<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->datetime('assigned_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->datetime('expires_at')->nullable(); // For temporary roles
            $table->string('assigned_by')->nullable(); // Track who assigned the role (manual/system/api)
            $table->timestamps();

            $table->unique(['role_id', 'user_id']);
            $table->index(['user_id', 'role_id']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
