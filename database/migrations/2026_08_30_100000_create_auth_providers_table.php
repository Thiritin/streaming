<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per way in that is not a password.
 *
 * Rows rather than settings keys: N providers x M fields cannot be declared in a
 * static registry, and a delete would have to sweep an unknown key prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_providers', function (Blueprint $table) {
            $table->id();
            // A Socialite core driver name, or 'oidc' for the generic case.
            $table->string('driver');
            // Stable URL segment: /auth/{key}/redirect. Never derived from the label,
            // which is editable, because the redirect URI is registered at the provider.
            $table->string('key')->unique();
            $table->string('label');
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->json('scopes')->nullable();
            $table->string('issuer_url')->nullable();
            $table->json('endpoints')->nullable();
            // Null means the generated /auth/{key}/callback. Unique so two rows cannot
            // both claim a path, which would break sign-in as if the provider were down.
            $table->string('redirect_path')->nullable()->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->boolean('grants_baseline')->default(true);
            $table->json('role_map')->nullable();
            $table->string('packages_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_providers');
    }
};
