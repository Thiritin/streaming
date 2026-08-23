<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planning and bookkeeping side of recording: whether a show is meant to be
 * published, who is looking after it, and how each of the two captures came back.
 *
 * There are always two, and they are not equals. The **stream** capture is what the
 * archive uploader mirrored off the source, and it happens whether anyone asks for it or
 * not. The **onsite** capture is a local recording made in the room, and it exists as a
 * fallback: if the stream came back clean, nobody needs to go looking for the card. That
 * relationship is the whole point of splitting them - `onsite_status` is only worth
 * filling in for the shows whose `stream_condition` says something went wrong, and a show
 * is only written off once both have failed.
 *
 * Separately from either, `archive_pgm_at` and `archive_iso_at` record the deposit into
 * long-term storage - the FTP server the programme mix and the isolated feeds are
 * uploaded to. That is a different question from "do we have usable material": a show can
 * be published and not archived, or archived and never published, and conflating the two
 * would let one of them quietly go untracked.
 *
 * Deliberately not a revival of `shows.recordable`, which the archive migration dropped.
 * That column was a gate: nothing was cut unless it was set. These are not. The uploader
 * still mirrors every segment of every source whatever these say, `is_published` still
 * decides what a viewer sees, and cutting a show marked `no` is not blocked anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            // Whether this show is meant to end up as a published recording.
            //
            // undecided - nobody has said either way, the default an imported slot arrives with
            // yes       - it is meant to be published
            // no        - deliberately not; not a gap, and not a to-do
            $table->string('publish_plan')->default('undecided')->after('announce_recording');

            $table->foreignId('recording_owner_id')->nullable()->after('publish_plan')
                ->constrained('users')->nullOnDelete();

            // How the stream capture came back. Null until someone has watched it.
            //
            // ok / no_audio / no_video / incomplete / lost
            $table->string('stream_condition')->nullable()->after('recording_owner_id');

            // The onsite capture, which only matters when the stream one failed.
            //
            // null      - nobody has looked yet
            // none      - there was no onsite recording of this show
            // expected  - someone has it and has not handed it over
            // received  - the master is with us, waiting to be imported
            // unusable  - we have it and it is no better than the stream
            $table->string('onsite_status')->nullable()->after('stream_condition');

            // The deposit into long-term storage: when the programme mix and the
            // isolated feeds went up to the archive FTP. Timestamps rather than flags,
            // because "when did that go up" is asked more often than "did it".
            $table->timestamp('archive_pgm_at')->nullable()->after('onsite_status');
            $table->timestamp('archive_iso_at')->nullable()->after('archive_pgm_at');

            $table->string('recording_note')->nullable()->after('archive_iso_at');

            // The overview reads "shows meant to be published that have nothing", so both
            // halves of that question want an index.
            $table->index(['publish_plan', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropIndex(['publish_plan', 'status']);
            $table->dropConstrainedForeignId('recording_owner_id');
            $table->dropColumn([
                'publish_plan', 'stream_condition', 'onsite_status',
                'archive_pgm_at', 'archive_iso_at', 'recording_note',
            ]);
        });
    }
};
