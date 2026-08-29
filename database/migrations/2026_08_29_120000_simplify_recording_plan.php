<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The recording plan, cut down to the questions people actually answer.
 *
 * Three things changed, and each of them removes a column somebody had to keep in step
 * with another one.
 *
 * **Publishing is one decision, not two.** `announce_recording` promised the audience a
 * recording and `publish_plan` recorded the intention to make one, and nothing kept them
 * together: a show could be planned for publication and never announced, or announced and
 * planned as `no`. `publish_plan` is now the only answer, and it is what the schedule
 * badge, the archive's pending tile and the recording API all read.
 *
 * **The stream capture has no failure modes worth naming.** It came back or it did not:
 * whatever went wrong with it, the job is the same - go and get the room's copy - so
 * `no_audio`, `no_video` and `incomplete` all collapse into `lost`.
 *
 * The onsite capture is the opposite, and keeps its detail, because there each answer
 * leads somewhere different: audio missing can be lifted off the desk, a part missing is
 * still worth publishing, and only `lost` means there is nothing. That is why it is
 * renamed `onsite_condition` - the two columns now ask the same question of two captures.
 *
 * **The archive FTP is not tracked here any more.** `archive_pgm_at` and `archive_iso_at`
 * were a second, unrelated errand living in the middle of the recording grid, with two
 * chips per row that had to be ticked by whoever remembered.
 *
 * In their place, `recording_tags`: free text a room can put its own process into - "saved
 * to nas", "handed to editor" - without a schema change per convention. The vocabulary is
 * whatever people type, offered back as suggestions, so a process is modelled by using it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The promise and the plan become one column, before the promise is dropped.
        DB::table('shows')
            ->where('announce_recording', true)
            ->where('publish_plan', 'undecided')
            ->update(['publish_plan' => 'yes']);

        // Whatever went wrong with the stream capture, the next move is the same.
        DB::table('shows')
            ->whereIn('stream_condition', ['no_audio', 'no_video', 'incomplete'])
            ->update(['stream_condition' => 'lost']);

        Schema::table('shows', function (Blueprint $table) {
            $table->renameColumn('onsite_status', 'onsite_condition');
        });

        // `received` was "the master is here", which is now simply a usable capture;
        // `none` and `unusable` both mean there is nothing to cut from. `expected` was a
        // chase in progress, which is nobody having looked yet.
        DB::table('shows')->where('onsite_condition', 'received')->update(['onsite_condition' => 'ok']);
        DB::table('shows')->whereIn('onsite_condition', ['none', 'unusable'])->update(['onsite_condition' => 'lost']);
        DB::table('shows')->where('onsite_condition', 'expected')->update(['onsite_condition' => null]);

        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['announce_recording', 'archive_pgm_at', 'archive_iso_at']);

            // Whatever else this installation tracks about a recording. Free text on
            // purpose: a room's process is its own, and a column per convention is how
            // this table got long enough to need cutting down.
            $table->json('recording_tags')->nullable()->after('recording_note');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->renameColumn('onsite_condition', 'onsite_status');
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->boolean('announce_recording')->default(false);
            $table->timestamp('archive_pgm_at')->nullable();
            $table->timestamp('archive_iso_at')->nullable();
            $table->dropColumn('recording_tags');
        });

        // Best effort: the onsite detail this migration introduced has nowhere to go back
        // to, so anything short of lost reads as a usable master.
        DB::table('shows')->where('publish_plan', 'yes')->update(['announce_recording' => true]);
        DB::table('shows')->where('onsite_status', 'lost')->update(['onsite_status' => 'unusable']);
        DB::table('shows')->whereNotNull('onsite_status')
            ->whereNot('onsite_status', 'unusable')
            ->update(['onsite_status' => 'received']);
    }
};
