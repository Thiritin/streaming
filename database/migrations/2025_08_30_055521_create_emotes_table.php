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
        Schema::create('emotes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // :emote_name: identifier
            $table->string('file_path')->nullable(); // Local file path
            $table->string('s3_key')->nullable(); // S3 object key
            $table->string('url')->nullable(); // CDN/S3 URL for the emote
            $table->foreignId('uploaded_by_user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_global')->default(false); // Available for all vs personal
            $table->integer('usage_count')->default(0);
            $table->timestamps();

            $table->index('is_approved');
            $table->index('is_global');
            $table->index(['is_approved', 'is_global']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emotes');
    }
};
