<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update the status column to include 'error' as a valid value
        // For SQLite (testing), we need to recreate the column
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            Schema::table('sources', function (Blueprint $table) {
                // SQLite doesn't support modifying columns directly
                // We'll handle this differently for testing
            });
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE sources ALTER COLUMN status SET DEFAULT 'offline'");
            DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_status_check CHECK (status IN ('online', 'offline', 'error'))");
        } else {
            // For MySQL, we can modify the column directly
            DB::statement("ALTER TABLE sources MODIFY COLUMN status VARCHAR(255) DEFAULT 'offline' CHECK (status IN ('online', 'offline', 'error'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert any sources with 'error' status to 'offline'
        DB::table('sources')->where('status', 'error')->update(['status' => 'offline']);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite doesn't support modifying columns directly
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sources DROP CONSTRAINT IF EXISTS sources_status_check');
            DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_status_check CHECK (status IN ('online', 'offline'))");
        } else {
            // For MySQL, revert the column constraint
            DB::statement("ALTER TABLE sources MODIFY COLUMN status VARCHAR(255) DEFAULT 'offline' CHECK (status IN ('online', 'offline'))");
        }
    }
};
