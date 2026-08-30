<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (account, provider). Both uniques matter: the first is what the callback
 * looks up, the second is what stops one provider being attached to an account twice
 * and leaving disconnect ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auth_provider_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar', 2048)->nullable();
            // What this provider granted at the last sign-in, so another provider's
            // sign-in can recompute the union without re-contacting anyone.
            $table->json('granted_role_ids')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['auth_provider_id', 'subject']);
            $table->unique(['auth_provider_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
