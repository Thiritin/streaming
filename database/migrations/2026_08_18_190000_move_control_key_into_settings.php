<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The control key used to be COMPANION_API_KEY in the environment and is now a row in
 * the settings table, edited at /manage > Settings > Control surfaces.
 *
 * An installation that already has a key in its environment keeps working across the
 * deploy: the value is copied in once, and the variable can then be dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $key = trim((string) env('COMPANION_API_KEY'));

        if ($key === '') {
            return;
        }

        if (DB::table('branding_settings')->where('key', 'control_key')->exists()) {
            return;
        }

        DB::table('branding_settings')->insert([
            'key' => 'control_key',
            'value' => $key,
            'description' => 'Control surface key, moved out of COMPANION_API_KEY.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A raw insert skips the model hook that drops the per-key cache, and a read
        // taken before this ran would otherwise keep answering "unset" for an hour.
        Cache::forget('branding_setting_control_key');
    }

    public function down(): void
    {
        // The row is the only copy now; deleting it on a rollback would lose the key.
    }
};
