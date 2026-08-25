<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reported comments, as a fifth thing a chat can be told about.
 *
 * The one category where the notification is the queue: a reported comment is
 * already invisible to the room, so the message is what somebody acts on, and an
 * interactive chat can approve it, delete it or ban its author without opening
 * the panel at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->boolean('notify_comments')->default(false)->after('notify_sources');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn('notify_comments');
        });
    }
};
