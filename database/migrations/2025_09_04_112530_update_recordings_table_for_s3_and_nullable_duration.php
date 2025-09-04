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
        Schema::table('recordings', function (Blueprint $table) {
            // Make duration nullable (it's already nullable in the existing migration)
            // Add thumbnail_path for S3 storage
            $table->string('thumbnail_path')->nullable()->after('thumbnail_url');
            $table->timestamp('thumbnail_updated_at')->nullable()->after('thumbnail_path');
            $table->text('thumbnail_capture_error')->nullable()->after('thumbnail_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn([
                'thumbnail_path',
                'thumbnail_updated_at',
                'thumbnail_capture_error',
            ]);
        });
    }
};
