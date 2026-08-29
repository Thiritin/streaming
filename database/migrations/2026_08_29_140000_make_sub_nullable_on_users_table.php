<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local account has no identity provider behind it, so it has no subject.
 *
 * The unique index stays: both MySQL 8 and PostgreSQL allow any number of NULLs in
 * one, so every OIDC account keeps its guarantee while local rows sit outside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sub')->nullable()->change();
        });
    }

    /**
     * Deliberately nothing. Putting the NOT NULL back would fail on both engines the
     * moment one local account exists, and there is no answer to invent for its
     * subject - a rollback that destroys accounts to satisfy a constraint is worse
     * than a column that stays wider than it needs to be.
     */
    public function down(): void
    {
        //
    }
};
