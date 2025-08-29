<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, backup existing stream keys (they'll be re-encrypted by the model)
        $sources = DB::table('sources')->select('id', 'stream_key')->get();
        
        Schema::table('sources', function (Blueprint $table) {
            // Drop the unique index first (TEXT columns can't have unique indexes without length specification)
            $table->dropUnique(['stream_key']);
            
            // Change column type from string to text to accommodate encrypted data
            $table->text('stream_key')->change();
        });
        
        // Force re-save to encrypt existing keys
        foreach ($sources as $source) {
            // The model will handle encryption when we retrieve and save
            $sourceModel = \App\Models\Source::find($source->id);
            if ($sourceModel) {
                // Temporarily store the plain key
                $plainKey = $source->stream_key;
                // Set it again to trigger encryption
                $sourceModel->stream_key = $plainKey;
                $sourceModel->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This will fail if encrypted values are longer than 255 chars
        // You may need to manually decrypt before rolling back
        Schema::table('sources', function (Blueprint $table) {
            $table->string('stream_key')->change();
            // Re-add the unique index
            $table->unique('stream_key');
        });
    }
};