<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link code now has two jobs, and they must not be confused for each other.
 *
 * `chat` is what the panel mints: it turns a group into an operator chat, which can be
 * given buttons that start and end shows. `viewer` is what somebody mints for
 * themselves from /settings, and it only ever attaches a Telegram account to the user
 * that made it. Pasting a viewer's code into a control-room group must not hand that
 * group the panel's buttons, so the purpose is stored rather than guessed from where
 * the code was used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_link_codes', function (Blueprint $table) {
            $table->string('purpose', 20)->default('chat')->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_link_codes', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
