<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the dispatcher last handed this recording to the notifier.
 *
 * Only so the every-few-minutes scan has something to exclude: who was actually
 * written to is `notification_deliveries`, and that is what keeps a viewer from being
 * told twice. Backfilled for everything already published, so switching notifications
 * on does not mail out an archive that existed before anybody subscribed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('published_at');
        });

        DB::table('recordings')
            ->where('is_published', true)
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
