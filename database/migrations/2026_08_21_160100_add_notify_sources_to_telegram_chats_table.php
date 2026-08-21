<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source monitoring, as a fourth thing a chat can be told about.
 *
 * The one category that is a log rather than a conversation: a source going online,
 * offline or into error is a moment, so it is posted and never edited, and a chat that
 * wants it usually wants it for one hall rather than for the whole installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->boolean('notify_sources')->default(false)->after('notify_recordings');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn('notify_sources');
        });
    }
};
