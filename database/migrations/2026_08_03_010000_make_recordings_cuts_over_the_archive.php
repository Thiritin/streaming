<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a recording from "a rendered video file" into "a time range over a source's
 * archive". See docs/dvr-archive-plan.md.
 *
 * The old pipeline extracted MP4s, concatenated them, trimmed, and re-encoded a second
 * ABR ladder, so a recording was an expensive artefact that had to be produced once and
 * kept. `shows.recordable` gated that cost, and the work could only start after the show
 * had ended and produced a bounded set of files.
 *
 * The archive uploader now mirrors every segment of every source continuously, so:
 *
 *  - Nothing is gated. `recordable` saved no storage, CPU or bandwidth; it only hid the
 *    result, which `recordings.is_published` already controls properly.
 *  - A cut is a playlist, regenerated from `starts_at`/`ends_at` on every save, so
 *    trimming is non-destructive and repeatable rather than a one-shot render.
 *  - Cutting no longer waits for a show to end, which matters because the main source
 *    stays online for the whole event and never produces an end to wait for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            // Which source's archive this is a view of. A recording is no longer tied to
            // a show having happened; it is a range over a continuous timeline.
            $table->foreignId('source_id')->nullable()->after('show_id')
                ->constrained()->nullOnDelete();

            // The cut. Distinct from shows.actual_start/actual_end on purpose: those
            // record when the show aired, these record what the viewer sees. They start
            // equal and diverge as an operator trims.
            $table->timestampTz('starts_at')->nullable()->after('date');
            $table->timestampTz('ends_at')->nullable()->after('starts_at');

            // Where the segments live, e.g. archive/prime. Stored rather than derived so
            // a recording still resolves if a source is later renamed.
            $table->string('archive_prefix')->nullable()->after('ends_at');

            // draft   - cut exists, playlist not built yet
            // ready   - playlist built, not visible to viewers
            // failed  - playlist build failed; message in build_error
            // Publication stays on is_published; this is about the artefact, not access.
            $table->string('status')->default('draft')->after('archive_prefix');
            $table->text('build_error')->nullable()->after('status');
            $table->timestampTz('playlist_built_at')->nullable()->after('build_error');

            $table->unsignedInteger('segment_count')->nullable()->after('playlist_built_at');

            $table->index(['status', 'is_published']);
            $table->index(['source_id', 'starts_at']);
        });

        // m3u8_url is now generated rather than supplied, so it cannot be required at
        // insert time: a draft exists before its playlist is built.
        Schema::table('recordings', function (Blueprint $table) {
            $table->string('m3u8_url')->nullable()->change();
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('recordable');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->boolean('recordable')->default(false)->after('auto_mode');
        });

        Schema::table('recordings', function (Blueprint $table) {
            $table->dropIndex(['status', 'is_published']);
            $table->dropIndex(['source_id', 'starts_at']);
            $table->dropConstrainedForeignId('source_id');
            $table->dropColumn([
                'starts_at', 'ends_at', 'archive_prefix', 'status',
                'build_error', 'playlist_built_at', 'segment_count',
            ]);
        });
    }
};
