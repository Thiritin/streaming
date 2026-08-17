<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much archive a cut spans, cached on the recording.
 *
 * A recording owns no storage of its own - it is a range over the shared segment
 * archive, so deleting one frees nothing. The number is still what an operator asks
 * for first ("how big is that show?"), and answering it per row would otherwise mean
 * reading hour indexes out of S3 on every page of the listing.
 *
 * Written by ArchivePlaylistService::build(), which is already holding the segments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->unsignedBigInteger('archive_bytes')->nullable()->after('segment_count');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('archive_bytes');
        });
    }
};
