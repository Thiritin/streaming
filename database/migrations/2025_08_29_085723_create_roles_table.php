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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->string('chat_color')->nullable(); // Hex color for chat, e.g., #FF5733
            $table->integer('priority')->default(0); // Higher priority = shown first
            $table->boolean('assigned_at_login')->default(true); // Whether this role is synced from registration system
            $table->boolean('is_staff')->default(false); // Mark admin/moderator roles
            $table->boolean('is_visible')->default(true); // Whether to show in chat
            $table->json('permissions')->nullable(); // Store any additional permissions as JSON
            $table->json('metadata')->nullable(); // Additional configuration
            $table->timestamps();
            
            $table->index('slug');
            $table->index('priority');
            $table->index('assigned_at_login');
            $table->index('is_staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};