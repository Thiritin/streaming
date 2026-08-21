<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forum topics are chats of their own, as far as this is concerned.
 *
 * A supergroup with topics on is one chat id and many threads, and a convention uses that
 * the same way it uses separate groups: a topic per stage, a topic for support. Keying a
 * row on the chat id alone made the whole group one configuration and put every post in
 * General, whichever topic somebody linked from.
 *
 * Zero means "not a topic": a plain group, a direct message, or the General topic, none of
 * which send a thread id. It is zero rather than null so the unique index still bites -
 * NULLs compare as distinct, which would have allowed two rows for the same plain group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->unsignedBigInteger('thread_id')->default(0)->after('chat_id');
            $table->string('topic_title')->nullable()->after('title');
        });

        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropUnique(['chat_id']);
            $table->unique(['chat_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropUnique(['chat_id', 'thread_id']);
            $table->unique(['chat_id']);
        });

        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn(['thread_id', 'topic_title']);
        });
    }
};
