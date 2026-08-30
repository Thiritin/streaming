<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat ban or timeout held against the identity behind a deleted account.
 *
 * Deleting an account takes its bans and timeouts with it - they hang off `user_id`
 * and the foreign key cascades - so without this, deleting and signing in again is
 * how a ban is lifted. The identity provider's `sub` outlives the row, which makes it
 * the only thing worth holding the sanction against.
 *
 * It is a holding pen, not a second ban list: a row is written when a sanctioned
 * account is deleted and consumed the moment that `sub` signs in again, which puts
 * the sanction back on the fresh account where every existing lookup already reads
 * it. Nothing else writes here, so a moderator lifting a ban has one place to do it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banned_subs', function (Blueprint $table) {
            $table->id();
            $table->string('sub')->index();
            // 'ban' or 'timeout', so each goes back to the table it came from.
            $table->string('kind', 16);
            $table->string('reason')->nullable();
            // Null is permanent, which only a ban can be.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['sub', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banned_subs');
    }
};
