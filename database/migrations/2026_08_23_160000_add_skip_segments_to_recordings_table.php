<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            // Stretches a viewer may be offered a way past: an intermission, a wait
            // before the doors, a changeover. Offsets in seconds from the start of
            // the recording, so a re-cut invalidates them and nothing else does.
            $table->json('skip_segments')->nullable()->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('skip_segments');
        });
    }
};
