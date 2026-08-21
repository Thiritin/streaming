<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per chat the bot talks to.
 *
 * There is one bot for the installation, configured at /manage > Settings > Telegram,
 * and as many chats as there are groups worth telling: a control room group, one per
 * hall, a maintainer's direct message. What each chat is told is its own decision,
 * which is why the flags live here rather than in the settings table.
 *
 * `interactive` is the only one that matters for safety: an info-only chat gets the
 * message and a link into /manage, an interactive one also gets buttons that start and
 * end shows. Anyone who can read the chat can press those, so switching it on is a
 * statement about who is in the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();

            // Telegram chat ids are 64-bit and negative for groups, and a supergroup
            // migration rewrites one entirely. Kept as a string: nothing here does
            // arithmetic on it, and it is only ever echoed back to the API.
            $table->string('chat_id')->unique();
            $table->string('title')->nullable();
            $table->string('type')->nullable();

            $table->boolean('enabled')->default(true);
            $table->boolean('interactive')->default(false);
            $table->boolean('notify_feedback')->default(false);
            $table->boolean('notify_shows')->default(false);

            // Which sources this chat cares about. Null or empty means all of them,
            // which is what a single control-room group wants; a hall group names its
            // own so the other stages stay quiet.
            $table->json('source_ids')->nullable();

            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_message_at')->nullable();

            // Why the bot last failed to write here: kicked, blocked, chat deleted. Set
            // alongside enabled=false so the panel can say what happened rather than
            // showing a row that silently stopped working.
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->index(['enabled', 'notify_feedback']);
            $table->index(['enabled', 'notify_shows']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chats');
    }
};
