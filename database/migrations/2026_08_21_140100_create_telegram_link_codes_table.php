<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived codes for the `/link <code>` path.
 *
 * The bot cannot be told which groups it belongs in from the outside: it only learns
 * about a chat when somebody in that chat talks to it. So the panel mints a code, a
 * person pastes `/link CODE` into the group, and the chat that answers is the one that
 * gets a row. The code expires because it is pasted into a room full of people.
 *
 * A used code is kept rather than deleted, so the panel can show which chat it turned
 * into and a second paste of the same code is refused rather than silently relinking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_link_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('telegram_chat_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_codes');
    }
};
