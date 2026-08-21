<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recordings, as a third thing a chat can be told about.
 *
 * Off by default like the other two: an existing chat should not start hearing about
 * every draft cut because the installation was upgraded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->boolean('notify_recordings')->default(false)->after('notify_shows');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn('notify_recordings');
        });
    }
};
