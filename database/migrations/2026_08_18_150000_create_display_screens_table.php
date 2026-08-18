<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per screen that has presented a display key, not per key.
 *
 * A key is a credential and can legitimately sit on four screens in four rooms, so
 * "what is this playing" and "put this on the main stage" only make sense one level
 * down, against the session the screen holds. The screen carries its row id in its
 * own session rather than the row carrying anything derived from the session id: the
 * session id is the screen's credential, it changes when the session regenerates,
 * and neither property belongs in a table read from /manage.
 *
 * Rows are disposable. A screen that stops polling stops being listed, and the
 * pruner drops it a day later; nothing here is worth keeping once the screen is
 * gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('display_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('embed_key_id')->constrained()->cascadeOnDelete();

            // What an operator calls this screen. Null until someone renames it, at
            // which point the key name stops being the only handle on a key that is
            // on more than one wall.
            $table->string('label')->nullable();

            // Reported by the screen on every poll, so it is what the screen believes
            // it is playing rather than what it was last told to play.
            $table->foreignId('current_source_id')->nullable()->constrained('sources')->nullOnDelete();

            // Set from /manage to move a screen. Cleared by the screen itself once it
            // reports it has arrived, which is what lets someone at the screen switch
            // channel afterwards without being dragged back a poll later.
            $table->foreignId('directed_source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->foreignId('directed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('directed_at')->nullable();

            // 'hub' or 'play'. A screen sitting on the hub cannot be started remotely
            // - autoplay needs a gesture - so the list has to say which it is.
            $table->string('page', 16)->default('hub');

            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('display_screens');
    }
};
