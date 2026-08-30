<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every notification this installation has decided to send, one row per viewer per
 * subject per channel.
 *
 * The unique key is the point of the table, not an index on it: the row is claimed
 * before anything is sent, so a job retried, a scheduler tick overlapping itself or a
 * recording republished twice cannot mail the same person the same thing again. A
 * claim that fails is a send that has already happened.
 *
 * It is also the record an operator reads on a user's page when somebody says they
 * were never told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What happened, as a category key: recording.published, show.live.
            $table->string('type', 40);

            // The row it was about. Kept as a plain id rather than a morph: the type
            // already names the table, and a deleted subject must not take the record
            // of having written to somebody with it.
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'subject_id', 'channel'], 'notification_deliveries_unique');
            $table->index(['type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
