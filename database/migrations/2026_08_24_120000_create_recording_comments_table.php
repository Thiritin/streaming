<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            /*
             * One level and no more: a reply carries the comment it answers, and a
             * reply to a reply is folded back onto the same parent by the controller.
             * Cascading is deliberate - a comment deleted by its author or a moderator
             * takes the thread hanging off it, because a reply left behind reads as an
             * answer to whatever ends up above it.
             */
            $table->foreignId('parent_id')->nullable()->constrained('recording_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // The watch page reads one recording's thread oldest first; the panel reads
            // every recording's newest first.
            $table->index(['recording_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_comments');
    }
};
