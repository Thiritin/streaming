<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A run of the convention: a name and the days it covers. Two things come off
         * those dates and nothing else does. The site is in its live state while today
         * falls inside a window, and everything a show or a recording belongs to is
         * filed under the run it happened in.
         *
         * Days, not instants: the window is the whole of the first day through the whole
         * of the last, which is how anybody says when a convention runs.
         */
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index('starts_on');
            $table->index('ends_on');
        });

        /*
         * A show carries the event; its recordings inherit it. The column on recordings
         * is the override, for an edit imported without a show and for the odd cut that
         * is filed under a different run than its show.
         */
        Schema::table('shows', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });

        Schema::table('recordings', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });

        Schema::dropIfExists('events');
    }
};
