<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Messages are stored as raw text now (the client renders emotes/mentions),
            // but legacy rows hold expanded emote markup that can exceed 500 chars.
            $table->text('message')->change();
            $table->foreignId('reply_to_id')->nullable()->after('source_id')
                ->constrained('messages')->nullOnDelete();
            $table->index(['source_id', 'created_at']);
        });

        Schema::create('chat_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable(); // null = permanent
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'lifted_at']);
        });

        Schema::create('chat_moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'created_at']);
            $table->index(['target_user_id', 'created_at']);
        });

        // Chat settings become per-source, with source_id = null acting as the global default.
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('id')
                ->constrained('sources')->cascadeOnDelete();
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropUnique('chat_settings_key_unique');
            $table->unique(['key', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropUnique(['key', 'source_id']);
            $table->dropConstrainedForeignId('source_id');
            $table->unique('key');
        });

        Schema::dropIfExists('chat_moderation_logs');
        Schema::dropIfExists('chat_bans');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['source_id', 'created_at']);
            $table->dropConstrainedForeignId('reply_to_id');
            $table->string('message', 500)->change();
        });
    }
};
