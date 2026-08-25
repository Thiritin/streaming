<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_comments', function (Blueprint $table) {
            /*
             * Hidden the moment somebody reports it, and cleared again when a
             * moderator approves. Not a boolean: when it went dark is the first
             * thing anyone asks about a comment that vanished.
             */
            $table->timestamp('hidden_at')->nullable()->after('body');
            /*
             * Approved once, hidden never again. Without this a single account can
             * keep a comment it dislikes permanently invisible by reporting it
             * afresh every time a moderator puts it back.
             */
            $table->timestamp('approved_at')->nullable()->after('hidden_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index('hidden_at');
        });

        Schema::create('recording_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_comment_id')->constrained()->cascadeOnDelete();
            // Who said so. Kept when the comment is approved, so a moderator can see
            // an account that reports everything it disagrees with.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message', 500);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // One report per person per comment: reporting twice is not two reports.
            $table->unique(['recording_comment_id', 'user_id']);
            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_comment_reports');

        Schema::table('recording_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['hidden_at', 'approved_at']);
        });
    }
};
