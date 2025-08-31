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
        Schema::rename('viewer_statistics', 'show_statistics');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('show_statistics', 'viewer_statistics');
    }
};
