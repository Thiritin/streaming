<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a viewer sends in from the site: general feedback from the top bar, or a
 * problem with a stream from the player.
 *
 * The point of the row is the `diagnostics` blob beside the message. "It keeps
 * buffering" is unactionable on its own; the same sentence with the browser, the
 * screen, the connection type, the bitrate the player had settled on and the buffer
 * it was running is usually enough to name the cause without a reply. The client
 * collects it, the server bounds it, and nobody has to ask a viewer to open dev
 * tools.
 *
 * `telegram` is optional and is the only contact detail collected. A signed-in
 * report already carries a user, but most people who hit a problem want to be asked
 * a follow-up somewhere they read, and that is not this site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table) {
            $table->id();

            // 'feedback' or 'issue'. Same table on purpose: both are somebody telling
            // us something, and an operator triaging wants one list, filtered.
            $table->string('type', 16)->default('feedback');

            // 'new', 'open' or 'resolved'.
            $table->string('status', 16)->default('new');

            // Null for a guest, and null again once an account is deleted. The report
            // outlives the reporter; it is a bug report, not a profile.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Stored without the leading @, so the same handle typed both ways is one
            // string. Displayed with it.
            $table->string('telegram', 64)->nullable();

            $table->text('message');

            // What they were watching. Both nullable: general feedback names neither,
            // and a show can be deleted long before its report is read.
            $table->foreignId('show_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();

            $table->string('url', 2048)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip', 45)->nullable();

            // Browser, screen, connection and player snapshot. Shape is deliberately
            // not pinned: the client sends what it can see, the server bounds depth,
            // key count and value length. See App\Support\Diagnostics.
            $table->json('diagnostics')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reports');
    }
};
