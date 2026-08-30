<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a viewer can be reached on, and what they asked to hear about.
 *
 * The address comes from the identity provider's `email` claim and is refreshed on
 * every sign-in, so there is nothing to verify here and nothing to keep in step by
 * hand. The Telegram id is the opposite: it is only ever written by the viewer
 * pasting a code into the bot, which is the only way we can learn it at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');

            // A private chat with the bot. Unique: one Telegram account is one
            // viewer, or an unlink would leave two accounts reading one inbox.
            $table->string('telegram_chat_id')->nullable()->unique()->after('avatar');
            $table->string('telegram_username')->nullable()->after('telegram_chat_id');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_username');

            // The standing subscription: every recording published, not one show.
            // Off by default - nobody is signed up by having an account.
            $table->boolean('notify_new_recordings')->default(false)->after('telegram_linked_at');

            // Which transports this viewer wants, as a list of channel names. Null
            // means "whatever they can be reached on", which is what a viewer who
            // pressed the bell without opening settings meant.
            $table->json('notification_channels')->nullable()->after('notify_new_recordings');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropColumn([
                'email',
                'telegram_chat_id',
                'telegram_username',
                'telegram_linked_at',
                'notify_new_recordings',
                'notification_channels',
            ]);
        });
    }
};
