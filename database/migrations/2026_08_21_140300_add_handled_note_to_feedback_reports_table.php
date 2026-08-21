<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed a report when it was not somebody with an account here.
 *
 * `handled_by` is a user id, which is the right answer for the panel and no answer at
 * all for the Resolve button in a Telegram group: the person pressing it is a Telegram
 * account, not a row in users. The note carries their handle so the report still says
 * who dealt with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('handled_note')->nullable()->after('handled_by');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropColumn('handled_note');
        });
    }
};
