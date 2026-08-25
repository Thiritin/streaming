<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_comment_hearts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One heart per person per comment: pressing it again takes it back
            // rather than adding a second.
            $table->unique(['recording_comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_comment_hearts');
    }
};
