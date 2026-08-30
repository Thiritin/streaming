<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A viewer's two subscriptions, each with a scope rather than a switch.
 *
 * The single "tell me about every new recording" flag could not say the thing most
 * people actually want, which is to hear about the handful of shows they followed and
 * nothing else. So each category now answers off, subscribed or any, and subscribed is
 * the shipped default: following a show is the act that opts somebody in, and the bell
 * on a show is the only thing they should have to find.
 *
 * The old flag maps onto the recordings scope: somebody who had asked for every new
 * recording keeps getting every new recording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('notify_shows_live', 12)->default('subscribed')->after('telegram_linked_at');
            $table->string('notify_recordings', 12)->default('subscribed')->after('notify_shows_live');
        });

        DB::table('users')
            ->where('notify_new_recordings', true)
            ->update(['notify_recordings' => 'any']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_new_recordings');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_new_recordings')->default(false)->after('telegram_linked_at');
        });

        DB::table('users')
            ->where('notify_recordings', 'any')
            ->update(['notify_new_recordings' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_shows_live', 'notify_recordings']);
        });
    }
};
