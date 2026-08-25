<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_comments', function (Blueprint $table) {
            // Shown next to the time as "(edited)". A comment somebody answered and
            // then rewrote is a different comment, and the reply under it only makes
            // sense if a reader can tell.
            $table->timestamp('edited_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('recording_comments', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
