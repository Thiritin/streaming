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
        Schema::create('shows', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('source_id')->constrained('sources')->onDelete('cascade');
            $table->datetime('scheduled_start');
            $table->datetime('scheduled_end');
            $table->datetime('actual_start')->nullable(); // When "Go Live" is clicked
            $table->datetime('actual_end')->nullable(); // When "End Livestream" is clicked
            $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled');
            $table->string('thumbnail_url')->nullable();
            $table->integer('viewer_count')->default(0);
            $table->integer('peak_viewer_count')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->json('tags')->nullable(); // For categorization
            $table->json('metadata')->nullable(); // Additional show data
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete(); // Which server is handling this show
            $table->timestamps();

            $table->index('slug');
            $table->index('status');
            $table->index('scheduled_start');
            $table->index('scheduled_end');
            $table->index('actual_start');
            $table->index('is_featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shows');
    }
};
