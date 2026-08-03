<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A viewer-facing promise that a show will be published afterwards.
 *
 * Deliberately not a revival of `shows.recordable`, which the previous migration dropped.
 * That flag claimed to control whether a show was captured at all; the archive uploader
 * now captures every source continuously, so nothing is gated and that meaning is gone.
 *
 * This flag answers a different question, asked by the audience rather than the system:
 * "can I watch this later if I miss it?" It drives a badge on the schedule and nothing
 * else. Capture is unconditional, publication is `recordings.is_published`, and this is
 * the announcement of intent that sits between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->boolean('announce_recording')->default(false)->after('auto_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('announce_recording');
        });
    }
};
