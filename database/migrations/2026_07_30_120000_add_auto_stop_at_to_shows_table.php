<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto mode gains an explicit hard stop.
 *
 * Auto mode used to end a show at `scheduled_end`, which conflates two different things:
 * when the programme guide says the slot is over, and the last moment a recording may
 * still be running. A dance scheduled 22:00-01:00 that nobody remembers to stop would keep
 * recording; the hard stop is the safety net that cuts it.
 *
 * Null means "no hard stop configured", which for an auto-mode show falls back to
 * `scheduled_end` - the behaviour before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->timestamp('auto_stop_at')->nullable()->after('auto_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('auto_stop_at');
        });
    }
};
