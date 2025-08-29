<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Drop FLV URL column if it exists
            if (Schema::hasColumn('sources', 'flv_url')) {
                $table->dropColumn('flv_url');
            }
            
            // Add HLS URL column if it doesn't exist
            if (!Schema::hasColumn('sources', 'hls_url')) {
                $table->string('hls_url')->nullable()->after('rtmp_url');
            }
        });
        
        // Update existing sources to generate HLS URLs based on slug
        $sources = DB::table('sources')->get();
        foreach ($sources as $source) {
            $slug = $source->slug ?: Str::slug($source->name);
            $hlsUrl = "http://localhost:8080/live/{$slug}.m3u8";
            DB::table('sources')
                ->where('id', $source->id)
                ->update([
                    'slug' => $slug,
                    'hls_url' => $hlsUrl
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Add back FLV URL column
            if (!Schema::hasColumn('sources', 'flv_url')) {
                $table->string('flv_url')->nullable()->after('rtmp_url');
            }
            
            // Remove HLS URL column
            if (Schema::hasColumn('sources', 'hls_url')) {
                $table->dropColumn('hls_url');
            }
        });
    }
};