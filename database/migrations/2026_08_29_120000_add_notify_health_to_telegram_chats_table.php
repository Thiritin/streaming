<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's alert list, as a sixth thing a chat can be told about.
 *
 * Infrastructure rather than programme: a server failing its health check, a box
 * running out of disk, the edges filling up. Nobody is sitting in front of /manage
 * at four in the morning, which is the hour these matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->boolean('notify_health')->default(false)->after('notify_comments');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn('notify_health');
        });
    }
};
