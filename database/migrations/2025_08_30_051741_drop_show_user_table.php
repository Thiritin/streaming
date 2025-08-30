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
        Schema::dropIfExists('show_user');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the show_user table if rolling back
        Schema::create('show_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained('shows')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->datetime('joined_at');
            $table->datetime('left_at')->nullable();
            $table->integer('watch_duration')->default(0); // in seconds
            $table->timestamps();

            $table->unique(['show_id', 'user_id', 'joined_at']);
            $table->index(['show_id', 'user_id']);
        });
    }
};
