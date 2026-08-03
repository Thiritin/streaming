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
    /**
     * Corrective only. The creating migration now declares these as plain `timestamp`, so
     * a fresh database never has the wrong type and this finds nothing to do. It exists
     * for databases that already ran that migration while it still said `timestampTz`.
     *
     * `ALTER COLUMN ... TYPE ... USING` is Postgres-specific, and SQLite has no real
     * timestamp types for it to correct, so both the driver and the current column type
     * are checked before touching anything.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['starts_at', 'ends_at', 'playlist_built_at'] as $column) {
            if (! $this->isTimestampTz($column)) {
                continue;
            }

            // USING keeps the instant Postgres currently holds; dropping the offset then
            // leaves the digits reading as the local time they were always meant to be.
            DB::statement(
                "ALTER TABLE recordings ALTER COLUMN {$column} TYPE timestamp without time zone "
                ."USING {$column} AT TIME ZONE 'UTC'"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['starts_at', 'ends_at', 'playlist_built_at'] as $column) {
            DB::statement(
                "ALTER TABLE recordings ALTER COLUMN {$column} TYPE timestamp with time zone "
                ."USING {$column} AT TIME ZONE 'UTC'"
            );
        }
    }

    protected function isTimestampTz(string $column): bool
    {
        $type = DB::selectOne(
            'SELECT data_type FROM information_schema.columns '
            .'WHERE table_name = ? AND column_name = ?',
            ['recordings', $column],
        );

        return $type && str_contains($type->data_type, 'with time zone');
    }
};
