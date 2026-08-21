<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the bot has already posted, so it can go back and edit it.
 *
 * A show message is not a notification that scrolls away: it starts as "starts in five
 * minutes" with a Start button, becomes "live" with an End button, and ends as a line
 * of history with no buttons at all. Editing needs the message id back, and one show
 * has one message per chat, which is what this table holds.
 *
 * The same is true of a feedback report: resolved in the panel or resolved from the
 * chat, the message in every chat that got it is rewritten to say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('message_id');

            // 'show' or 'feedback'. A string rather than a morph, because these are the
            // only two subjects and the sender needs to pick a builder by it anyway.
            $table->string('kind', 32);
            $table->unsignedBigInteger('subject_id');

            // Where the message is in its own little state machine: upcoming, live,
            // confirm_end, ended, resolved. Only confirm_end is not derivable from the
            // subject, which is the whole reason it is stored.
            $table->string('state', 32)->nullable();

            $table->timestamps();

            $table->unique(['telegram_chat_id', 'message_id']);
            $table->index(['kind', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
