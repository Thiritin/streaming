<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys handed to unattended displays and to people who want a VLC URL.
 *
 * A key is not a user. It authenticates a screen, not a person, so it carries no
 * identity, no roles and no chat. Presenting one exchanges it for a session that
 * can mint playback tokens for any source, which is what lets a display switch
 * channel without the key itself being a wildcard credential.
 *
 * Revocation is deleting the row. There is no expiry: the whole point is a URL
 * that can be typed into a screen once and left alone for a week - hence the code
 * is 8 Crockford base32 characters rather than a random 40, since the screens it
 * goes on rarely have a keyboard, let alone a clipboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Encrypted rather than hashed, because an operator has to be able to read
            // it back to re-copy the code onto a second screen. The hash beside it is
            // what lookups actually use - an encrypted column cannot be queried. The
            // hash is HMAC-SHA256 under APP_KEY, not a bare digest: the code is short
            // enough that an unkeyed hash would be sweepable if this table leaked.
            $table->text('key');
            $table->string('key_hash', 64)->unique();

            // Set to sign every screen holding this code back out without revoking
            // the code itself, for the screen that was carried off or left logged in
            // somewhere it should not be. Sessions older than this stop resolving.
            $table->timestamp('signed_out_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_keys');
    }
};
