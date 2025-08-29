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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable(); // e.g., "Hall 3", "Outside", "Main Stage"
            $table->string('stream_key')->unique();
            $table->string('rtmp_url')->nullable();
            $table->string('flv_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false); // Mark if this is the main/permanent stream
            $table->integer('priority')->default(0); // For ordering sources
            $table->json('metadata')->nullable(); // Additional configuration
            $table->timestamps();
            
            $table->index('slug');
            $table->index('is_active');
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};