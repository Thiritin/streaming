<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One viewer asking to be told about one show: when it goes live, and when its
 * recording is published.
 *
 * Deliberately not a feature of the standing bell. Somebody who wants to know the
 * moment a single panel starts is not asking to be pinged for every stream of the
 * convention, and somebody who wants the archive digest is not asking to be woken at
 * two in the morning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('show_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'show_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_subscriptions');
    }
};
