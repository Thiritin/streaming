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
        // First add columns without unique constraint
        Schema::table('recordings', function (Blueprint $table) {
            if (!Schema::hasColumn('recordings', 'show_id')) {
                $table->unsignedBigInteger('show_id')->nullable()->after('id');
                $table->foreign('show_id')->references('id')->on('shows')->onDelete('set null');
                $table->index('show_id');
            }
            
            if (!Schema::hasColumn('recordings', 'slug')) {
                $table->string('slug')->nullable()->after('title');
            }
        });
        
        // Generate slugs for existing records
        $recordings = \App\Models\Recording::all();
        foreach ($recordings as $recording) {
            $baseSlug = \Illuminate\Support\Str::slug($recording->title);
            $slug = $baseSlug;
            $count = 1;
            
            while (\App\Models\Recording::where('slug', $slug)->where('id', '!=', $recording->id)->exists()) {
                $slug = $baseSlug . '-' . $count;
                $count++;
            }
            
            $recording->slug = $slug;
            $recording->save();
        }
        
        // Now make slug not nullable and add unique constraint
        if (Schema::hasColumn('recordings', 'slug')) {
            Schema::table('recordings', function (Blueprint $table) {
                $table->string('slug')->nullable(false)->unique()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropForeign(['show_id']);
            $table->dropIndex(['show_id']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['show_id', 'slug']);
        });
    }
};