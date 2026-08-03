<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the cut markers from `timestamptz` back to plain `timestamp`.
 *
 * The previous migration made starts_at/ends_at timezone-aware, which looked like the
 * more careful choice and was in fact a bug.
 *
 * Laravel serialises datetimes for Postgres with `Y-m-d H:i:s` and no offset. A Carbon in
 * the app timezone (Europe/Berlin) therefore reaches a `timestamptz` column as bare
 * wall-clock digits, and Postgres reads them in the session timezone (UTC). The instant
 * silently moves by the offset on write. In memory the value is right, so a cut built
 * immediately after saving works, and only a later re-read shows the shift - which is
 * exactly how it surfaced: a recording that built correctly, then resolved to zero
 * segments once reloaded.
 *
 * Everything else in this schema (shows.actual_start, recordings.date) is a naive
 * `timestamp` interpreted in the app timezone. Matching that convention makes the round
 * trip lossless. Comparisons against segment timestamps stay correct because
 * ArchivePlaylistService normalises both ends to UTC before it compares anything, which
 * is the only place the distinction actually matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Values written since the previous migration were stored with their digits taken
        // as UTC rather than as app-local time. Converting the column with USING keeps the
        // instant Postgres currently believes in, then the offset is removed so the digits
        // read back as the local time they were always meant to be.
        DB::statement(
            'ALTER TABLE recordings ALTER COLUMN starts_at TYPE timestamp without time zone '
            ."USING starts_at AT TIME ZONE 'UTC'"
        );
        DB::statement(
            'ALTER TABLE recordings ALTER COLUMN ends_at TYPE timestamp without time zone '
            ."USING ends_at AT TIME ZONE 'UTC'"
        );
        DB::statement(
            'ALTER TABLE recordings ALTER COLUMN playlist_built_at TYPE timestamp without time zone '
            ."USING playlist_built_at AT TIME ZONE 'UTC'"
        );
    }

    public function down(): void
    {
        foreach (['starts_at', 'ends_at', 'playlist_built_at'] as $column) {
            DB::statement(
                "ALTER TABLE recordings ALTER COLUMN {$column} TYPE timestamp with time zone "
                ."USING {$column} AT TIME ZONE 'UTC'"
            );
        }
    }
};
